<?php

declare(strict_types=1);

namespace App\Jobs\RetroAchievements;

use App\Exceptions\RetroAchievements\RetroAchievementsException;
use App\Models\RaGame;
use App\Models\RaProgress;
use App\Models\User;
use App\Services\RetroAchievementsService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Find the games whose progress has drifted, and only those.
 *
 * The recent-unlocks pulse cannot see everything: unlocks can be revoked, a
 * set can be re-scored, and a long outage leaves a hole. Asking each game in
 * turn would be hundreds of requests, so this asks one paginated endpoint what
 * the totals should be, compares against what we hold, and sends a full
 * reconciliation only where the two disagree.
 */
class ReconcileProgress implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 3;

    public int $uniqueFor = 400;

    public function __construct(public readonly int $userId)
    {
        $this->onQueue('ra-progress');
    }

    public function uniqueId(): string
    {
        return 'ra-reconcile:'.$this->userId;
    }

    public function handle(RetroAchievementsService $provider): void
    {
        $user = User::find($this->userId);
        $username = trim((string) $user?->retroachievements_username);

        if ($user === null || $username === '') {
            return;
        }

        $held = RaProgress::query()
            ->where('user_id', $this->userId)
            ->get(['ra_game_id', 'unlocked_count', 'unlocked_hardcore_count', 'highest_award_kind'])
            ->keyBy('ra_game_id');

        $queued = [];
        $offset = 0;

        do {
            try {
                $page = $provider->completionProgress($username, $offset);
            } catch (RetroAchievementsException $e) {
                $this->waitAndRetry($e);

                return;
            }

            foreach ($page['results'] as $row) {
                $gameId = (int) Arr::get($row, 'GameID', 0);

                if ($gameId <= 0) {
                    continue;
                }

                // Only games in this library. A person's RetroAchievements
                // account covers everything they have ever played, and most of
                // it is not on this disk.
                if (! RaGame::query()->whereKey($gameId)->exists()) {
                    continue;
                }

                $ours = $held->get($gameId);
                $theirs = [
                    (int) Arr::get($row, 'NumAwarded', 0),
                    (int) Arr::get($row, 'NumAwardedHardcore', 0),
                    Arr::get($row, 'HighestAwardKind'),
                ];

                $mine = $ours === null ? [null, null, null] : [
                    (int) $ours->unlocked_count,
                    (int) $ours->unlocked_hardcore_count,
                    $ours->highest_award_kind?->value,
                ];

                if ($theirs !== $mine) {
                    $queued[$gameId] = $gameId;
                }
            }

            $offset += count($page['results']);
        } while ($page['results'] !== [] && $offset < $page['total']);

        // Plus anything a set change invalidated. points_possible is wrong on
        // those whatever the counts say, so they are not detectable by the
        // comparison above.
        foreach (RaProgress::query()->where('user_id', $this->userId)->where('stale', true)->pluck('ra_game_id') as $gameId) {
            $queued[(int) $gameId] = (int) $gameId;
        }

        foreach ($queued as $gameId) {
            SyncGameProgress::dispatch($this->userId, $gameId);
        }

        Log::info('RetroAchievements progress reconciled.', [
            'user' => $this->userId,
            'games_queued' => count($queued),
        ]);
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
            Log::error('RetroAchievements reconciliation abandoned.', [
                'user' => $this->userId,
                'reason' => $e->getMessage(),
            ]);

            $this->fail($e);

            return;
        }

        $this->release($e->retryAfter() ?? 300);
    }
}
