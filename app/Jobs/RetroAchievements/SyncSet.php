<?php

declare(strict_types=1);

namespace App\Jobs\RetroAchievements;

use App\Enums\AchievementKind;
use App\Exceptions\RetroAchievements\RetroAchievementsException;
use App\Models\RaAchievement;
use App\Models\RaGame;
use App\Services\RetroAchievementsProgress;
use App\Services\RetroAchievementsService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Download one game's achievement set.
 *
 * Idempotent by upsert: running it twice on the same answer changes nothing.
 * An achievement that has left the set is stamped rather than deleted, because
 * somebody may have unlocked it and the row is the only record of that.
 */
class SyncSet implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 3;

    public int $uniqueFor = 200;

    public function __construct(public readonly int $raGameId)
    {
        $this->onQueue('ra');
    }

    public function uniqueId(): string
    {
        return 'ra-set:'.$this->raGameId;
    }

    public function handle(RetroAchievementsService $provider, RetroAchievementsProgress $progress): void
    {
        try {
            $payload = $provider->gameExtended($this->raGameId);
        } catch (RetroAchievementsException $e) {
            $this->waitAndRetry($e);

            return;
        }

        if ($payload === null) {
            Log::warning('RetroAchievements knows no game with this id.', ['ra_game_id' => $this->raGameId]);

            return;
        }

        $now = now();
        $achievements = (array) Arr::get($payload, 'Achievements', []);

        $rows = [];

        foreach ($achievements as $key => $achievement) {
            $achievement = (array) $achievement;
            $id = (int) (Arr::get($achievement, 'ID') ?? $key);

            if ($id <= 0) {
                continue;
            }

            $rows[] = [
                'id' => $id,
                'ra_game_id' => $this->raGameId,
                'title' => (string) Arr::get($achievement, 'Title', ''),
                'description' => Arr::get($achievement, 'Description'),
                'points' => (int) Arr::get($achievement, 'Points', 0),
                'true_ratio' => (int) Arr::get($achievement, 'TrueRatio', 0),
                'badge_name' => Arr::get($achievement, 'BadgeName'),
                'kind' => $this->kind(Arr::get($achievement, 'type')),
                'display_order' => (int) Arr::get($achievement, 'DisplayOrder', 0),
                // f=5 brings back unofficial and demoted entries alongside the
                // real ones. Flags 3 is the official set; anything else is
                // stored but never counted.
                'core' => (int) Arr::get($achievement, 'Flags', 3) === 3,
                'num_awarded' => (int) Arr::get($achievement, 'NumAwarded', 0),
                'num_awarded_hardcore' => (int) Arr::get($achievement, 'NumAwardedHardcore', 0),
                'removed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($payload, $rows, $now) {
            RaGame::query()->updateOrCreate(['id' => $this->raGameId], [
                'ra_console_id' => (int) Arr::get($payload, 'ConsoleID', 0),
                'title' => (string) Arr::get($payload, 'Title', ''),
                'image_icon' => Arr::get($payload, 'ImageIcon'),
                'num_achievements' => count(array_filter($rows, fn (array $r) => $r['core'])),
                'points_total' => array_sum(array_map(
                    fn (array $r) => $r['core'] ? $r['points'] : 0,
                    $rows,
                )),
                // The denominator for "3.4% of players". Free here, and there
                // is no other endpoint that would give it to us.
                'num_distinct_players' => (int) Arr::get($payload, 'NumDistinctPlayers', 0),
                'num_distinct_players_hardcore' => (int) Arr::get($payload, 'NumDistinctPlayersHardcore', 0),
                'set_synced_at' => $now,
                'set_updated_at' => Arr::get($payload, 'Updated'),
            ]);

            foreach (array_chunk($rows, 250) as $chunk) {
                DB::table('ra_achievements')->upsert($chunk, ['id'], [
                    'ra_game_id', 'title', 'description', 'points', 'true_ratio', 'badge_name',
                    'kind', 'display_order', 'core', 'num_awarded', 'num_awarded_hardcore',
                    'removed_at', 'updated_at',
                ]);
            }

            // Anything this answer did not mention has left the set. Stamped,
            // never deleted: an unlock row points at it, and the unlock is a
            // fact about a person rather than about the set.
            RaAchievement::query()
                ->where('ra_game_id', $this->raGameId)
                ->whereNotIn('id', array_column($rows, 'id') ?: [0])
                ->whereNull('removed_at')
                ->update(['removed_at' => $now]);
        });

        // points_possible on every existing progress row is now wrong, and
        // only the progress job may put it right.
        $stale = $progress->markStale($this->raGameId);

        Log::info('RetroAchievements set synced.', [
            'ra_game_id' => $this->raGameId,
            'achievements' => count($rows),
            'progress_marked_stale' => $stale,
        ]);
    }

    private function kind(mixed $type): ?string
    {
        return is_string($type) ? AchievementKind::tryFrom($type)?->value : null;
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
            Log::error('RetroAchievements set sync abandoned.', [
                'ra_game_id' => $this->raGameId,
                'reason' => $e->getMessage(),
            ]);

            $this->fail($e);

            return;
        }

        $this->release($e->retryAfter() ?? 300);
    }
}
