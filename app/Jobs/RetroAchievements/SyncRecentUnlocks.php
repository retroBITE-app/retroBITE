<?php

declare(strict_types=1);

namespace App\Jobs\RetroAchievements;

use App\Exceptions\RetroAchievements\RetroAchievementsException;
use App\Models\User;
use App\Services\RetroAchievementsProgress;
use App\Services\RetroAchievementsService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The cheap pulse: what has been unlocked lately.
 *
 * One request covers everything a person played since the last run, which is
 * why it can run every quarter of an hour. The window is measured from the
 * last successful run rather than fixed, so a container that was off for three
 * hours asks for three hours and no unlock falls through the gap.
 */
class SyncRecentUnlocks implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
    use WaitsOutRateLimits;

    public int $timeout = 120;

    public int $uniqueFor = 200;

    /** RetroAchievements will not look back further than this in one request. */
    private const MAX_MINUTES = 60 * 24 * 7;

    public function __construct(public readonly int $userId)
    {
        $this->onQueue('ra-progress');
    }

    public function uniqueId(): string
    {
        return 'ra-recent:'.$this->userId;
    }

    public function handle(RetroAchievementsService $provider, RetroAchievementsProgress $progress): void
    {
        $user = User::find($this->userId);
        $username = trim((string) $user?->retroachievements_username);

        if ($user === null || $username === '') {
            return;
        }

        $startedAt = now();

        try {
            $rows = $provider->recentAchievements($username, $this->minutes($user));
        } catch (RetroAchievementsException $e) {
            $this->waitAndRetry($e);

            return;
        }

        // This endpoint is the odd one out: one row per unlock with a hardcore
        // boolean, where everything else gives two dates on one achievement.
        $normalised = array_map(fn (array $row) => $provider->normaliseRecent($row), $rows);
        $normalised = array_values(array_filter($normalised, fn (array $row) => $row['achievement_id'] > 0));

        $outcome = $progress->writeUnlocks($this->userId, $normalised);

        foreach (array_unique(array_column($normalised, 'game_id')) as $gameId) {
            if (in_array($gameId, $outcome['unknown_games'], true)) {
                continue;
            }

            $progress->recompute($this->userId, (int) $gameId);
        }

        // An unlock for an achievement we have no row for is a set that grew
        // since we last fetched it, not corruption. Fetch it and let the next
        // run pick the unlock up rather than dropping it for good.
        foreach ($outcome['unknown_games'] as $gameId) {
            SyncSet::dispatch((int) $gameId);
        }

        // Stamped with when the request went out, not when it came back, so a
        // slow response cannot leave a sliver of time nobody ever asks about.
        $user->forceFill(['retroachievements_synced_at' => $startedAt])->save();

        Log::info('RetroAchievements recent unlocks synced.', [
            'user' => $this->userId,
            'unlocks' => $outcome['written'],
            'sets_queued' => count($outcome['unknown_games']),
        ]);
    }

    private function minutes(User $user): int
    {
        $since = $user->retroachievements_synced_at;
        $margin = (int) config('retroachievements.recent_margin', 30);

        if ($since === null) {
            return self::MAX_MINUTES;
        }

        return (int) min(self::MAX_MINUTES, max(1, $since->diffInMinutes(now())) + $margin);
    }

    private function waitAndRetry(RetroAchievementsException $e): void
    {
        // Run straight from a command rather than off a queue, where fail()
        // has no job to fail and swallows the reason — the command would print
        // success and store nothing.
        if ($this->job === null) {
            throw $e;
        }

        if (! $e->retryable()) {
            Log::error('RetroAchievements recent sync abandoned.', [
                'user' => $this->userId,
                'reason' => $e->getMessage(),
            ]);

            $this->fail($e);

            return;
        }

        $this->release($e->retryAfter() ?? 300);
    }
}
