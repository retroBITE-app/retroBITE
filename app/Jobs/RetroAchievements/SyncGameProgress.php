<?php

declare(strict_types=1);

namespace App\Jobs\RetroAchievements;

use App\Enums\AwardKind;
use App\Exceptions\RetroAchievements\RetroAchievementsException;
use App\Models\RaGame;
use App\Models\User;
use App\Services\RetroAchievementsProgress;
use App\Services\RetroAchievementsService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reconcile one person's progress in one game, in full.
 *
 * The expensive path, so it only runs for the games the nightly comparison
 * found had drifted, plus the ones a set change marked stale. That it is rare
 * is what makes the second request — for the site rank — affordable.
 */
class SyncGameProgress implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
    use WaitsOutRateLimits;

    public int $timeout = 120;

    public int $uniqueFor = 200;

    public function __construct(
        public readonly int $userId,
        public readonly int $raGameId,
    ) {
        $this->onQueue('ra-progress');
    }

    public function uniqueId(): string
    {
        return 'ra-game-progress:'.$this->userId.':'.$this->raGameId;
    }

    public function handle(RetroAchievementsService $provider, RetroAchievementsProgress $progress): void
    {
        $user = User::find($this->userId);
        $username = trim((string) $user?->retroachievements_username);

        if ($user === null || $username === '') {
            return;
        }

        // The set has to exist locally before progress can point at it: the
        // progress row has a foreign key to ra_games, and the counters are
        // worked out from achievements we may not have yet.
        if (! RaGame::query()->whereKey($this->raGameId)->exists()) {
            SyncSet::dispatch($this->raGameId);

            return;
        }

        try {
            $payload = $provider->gameInfoAndUserProgress($username, $this->raGameId);
            $rank = $provider->userGameRankAndScore($username, $this->raGameId);
        } catch (RetroAchievementsException $e) {
            $this->waitAndRetry($e);

            return;
        }

        if ($payload === null) {
            return;
        }

        $progress->writeUnlocks($this->userId, $progress->unlocksIn($payload));

        $progress->recompute($this->userId, $this->raGameId, [
            // Straight from the provider. Working an award out locally would
            // mean reimplementing their rules and getting a different answer.
            'highest_award_kind' => $this->award(Arr::get($payload, 'HighestAwardKind')),
            'highest_award_at' => $this->moment(Arr::get($payload, 'HighestAwardDate')),

            // An empty answer means the person has no progress in this game,
            // which is "no rank" rather than a failure.
            'site_rank' => $rank === null ? null : (int) Arr::get($rank, 'UserRank'),
            'site_score' => $rank === null ? null : (int) Arr::get($rank, 'TotalScore'),
        ]);

        Log::info('RetroAchievements game progress synced.', [
            'user' => $this->userId,
            'ra_game_id' => $this->raGameId,
        ]);
    }

    private function award(mixed $kind): ?string
    {
        return is_string($kind) ? AwardKind::tryFrom($kind)?->value : null;
    }

    /**
     * Parse a date the provider sent, whatever shape it chose.
     *
     * The two endpoints disagree: unlock dates come as '2018-09-25 21:41:23'
     * and HighestAwardDate as '2026-01-02T23:36:44+00:00'. The counters are
     * written through the query builder rather than Eloquent, so nothing casts
     * them on the way past, and MariaDB rejected the second outright — taking
     * the whole update with it for every game the person had actually beaten.
     */
    private function moment(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            Log::warning('Unparseable date from RetroAchievements.', [
                'ra_game_id' => $this->raGameId,
                'value' => $value,
            ]);

            return null;
        }
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
            Log::error('RetroAchievements game progress sync abandoned.', [
                'user' => $this->userId,
                'ra_game_id' => $this->raGameId,
                'reason' => $e->getMessage(),
            ]);

            $this->fail($e);

            return;
        }

        $this->release($e->retryAfter() ?? 300);
    }
}
