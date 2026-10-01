<?php

declare(strict_types=1);

namespace App\Jobs\RetroAchievements;

use App\Exceptions\RetroAchievements\RetroAchievementsException;
use App\Models\RaConsoleSync;
use App\Services\RetroAchievementsMatcher;
use App\Services\RetroAchievementsService;
use App\Support\RetroAchievements\LibraryConsoles;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Download one console's hash index.
 *
 * This is what makes identification free. RetroAchievements will list every
 * game on a console together with every file hash that identifies it, so
 * fetching that once a night turns twenty thousand identifications into
 * twenty thousand local lookups.
 *
 * It finishes by rematching the games that had no set, which costs nothing —
 * their hashes are already on the file rows — and is the whole reason "no
 * match" is never final here.
 */
class SyncHashIndex implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
    use WaitsOutRateLimits;

    /** A console's index is megabytes of JSON and thousands of rows. */
    public int $timeout = 900;

    public int $uniqueFor = 1000;

    public function __construct(public readonly int $raConsoleId)
    {
        $this->onQueue('ra');
    }

    public function uniqueId(): string
    {
        return 'ra-index:'.$this->raConsoleId;
    }

    /**
     * Queue an index sync for every console the library has games for.
     *
     * @return int how many were queued
     */
    public static function queueForLibrary(): int
    {
        $ids = LibraryConsoles::mapped()->keys();

        foreach ($ids as $id) {
            self::dispatch((int) $id);
        }

        return $ids->count();
    }

    public function handle(RetroAchievementsService $provider, RetroAchievementsMatcher $matcher): void
    {
        try {
            $games = $provider->gameList($this->raConsoleId);
        } catch (RetroAchievementsException $e) {
            $this->waitAndRetry($e);

            return;
        }

        // Every row written this run is stamped with the same instant, so
        // anything left carrying an older one is a hash RetroAchievements no
        // longer lists. Cheaper and safer than a whereNotIn over tens of
        // thousands of hashes, which would pass MariaDB's placeholder limit.
        $startedAt = now();

        $gameRows = [];
        $hashRows = [];

        foreach ($games as $game) {
            $id = (int) Arr::get($game, 'ID', 0);

            if ($id <= 0) {
                continue;
            }

            // Every row carries the same keys, because upsert() sorts each row
            // but does not fill in the ones a row happens to be missing, and a
            // ragged batch is a column-count error rather than a null.
            $gameRows[] = [
                'id' => $id,
                'ra_console_id' => $this->raConsoleId,
                'title' => (string) Arr::get($game, 'Title', ''),
                'image_icon' => Arr::get($game, 'ImageIcon'),
                'num_achievements' => (int) Arr::get($game, 'NumAchievements', 0),
                'points_total' => (int) Arr::get($game, 'Points', 0),
                'created_at' => $startedAt,
                'updated_at' => $startedAt,
            ];

            foreach ((array) Arr::get($game, 'Hashes', []) as $hash) {
                if (! is_string($hash) || $hash === '') {
                    continue;
                }

                $hashRows[] = [
                    'hash' => strtolower($hash),
                    'ra_game_id' => $id,
                    'ra_console_id' => $this->raConsoleId,
                    'created_at' => $startedAt,
                    'updated_at' => $startedAt,
                ];
            }
        }

        // Chunked because upsert() does not chunk, and MariaDB stops at 65 535
        // placeholders in one statement — which a large console passes easily.
        foreach (array_chunk($gameRows, 250) as $chunk) {
            DB::table('ra_games')->upsert($chunk, ['id'], [
                'ra_console_id', 'title', 'image_icon', 'num_achievements', 'points_total', 'updated_at',
            ]);
        }

        foreach (array_chunk($hashRows, 500) as $chunk) {
            DB::table('ra_game_hashes')->upsert($chunk, ['hash', 'ra_game_id'], ['ra_console_id', 'updated_at']);
        }

        // Hashes that have gone are deleted; ra_games rows never are. A set
        // dropping a hash is ordinary housekeeping at their end, but deleting
        // the game row would cascade a person's progress away with it.
        $removed = $this->pruneHashes($hashRows);

        RaConsoleSync::query()->updateOrCreate(
            ['ra_console_id' => $this->raConsoleId],
            ['synced_at' => now(), 'games' => count($gameRows), 'hashes' => count($hashRows)],
        );

        $rematched = 0;

        foreach (LibraryConsoles::keysFor($this->raConsoleId) as $key) {
            $rematched += $matcher->rematchConsole($key, $this->raConsoleId);
        }

        Log::info('RetroAchievements hash index synced.', [
            'ra_console_id' => $this->raConsoleId,
            'games' => count($gameRows),
            'hashes' => count($hashRows),
            'removed' => $removed,
            'rematched' => $rematched,
        ]);
    }

    /**
     * Drop hashes this sync did not see.
     *
     * By comparing the rows themselves rather than their timestamps. A
     * timestamp would be the obvious way and does not work: these columns hold
     * whole seconds, so two syncs inside the same second leave the stale rows
     * looking freshly written. Nor can it be one `whereNotIn`, because a large
     * console has more hashes than MariaDB allows placeholders in a statement.
     *
     * Skipped entirely when the response held none: a console that had
     * thousands of hashes coming back empty is far likelier to be a bad
     * response than a real emptying, and clearing the index would make every
     * game on that console unidentifiable until the next run.
     *
     * @param  array<int, array<string, mixed>>  $hashRows
     */
    private function pruneHashes(array $hashRows): int
    {
        if ($hashRows === []) {
            return 0;
        }

        $keep = [];

        foreach ($hashRows as $row) {
            $keep[$row['hash'].':'.$row['ra_game_id']] = true;
        }

        $stale = [];

        DB::table('ra_game_hashes')
            ->where('ra_console_id', $this->raConsoleId)
            ->select('id', 'hash', 'ra_game_id')
            ->orderBy('id')
            ->chunk(2000, function ($rows) use ($keep, &$stale) {
                foreach ($rows as $row) {
                    if (! isset($keep[$row->hash.':'.$row->ra_game_id])) {
                        $stale[] = $row->id;
                    }
                }
            });

        $removed = 0;

        foreach (array_chunk($stale, 1000) as $chunk) {
            $removed += DB::table('ra_game_hashes')->whereIn('id', $chunk)->delete();
        }

        return $removed;
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
            Log::error('RetroAchievements index sync abandoned.', [
                'ra_console_id' => $this->raConsoleId,
                'reason' => $e->getMessage(),
            ]);

            $this->fail($e);

            return;
        }

        $this->release($e->retryAfter() ?? 300);
    }
}
