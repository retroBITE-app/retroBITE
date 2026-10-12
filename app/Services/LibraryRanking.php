<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The retroBite rank: every scored game in the library in one order, across
 * all consoles, 1 the best.
 *
 * A score is out of a hundred and whole, so a library of thousands shares
 * each number with dozens of others. The rank breaks those ties by how much
 * stands behind the score — LaunchBox votes, then RetroAchievements players —
 * and then by title, so no two games share a place and the order is the same
 * every time it is worked out.
 *
 * Worked out in one statement for the whole library: a single score moving
 * shifts everything between its old place and its new one. So a score that
 * moves only marks the ranks stale, and the scheduler works them out every
 * ten minutes when they are (`retrobite:rank --stale`). Worked out after
 * every match instead, an identification run of thousands rewrote every
 * game's row about once a second.
 */
final class LibraryRanking
{
    private const STALE_KEY = 'library.ranking.stale';

    /** Say a score has moved since the ranks were last worked out. */
    public function markStale(): void
    {
        Cache::forever(self::STALE_KEY, true);
    }

    public function isStale(): bool
    {
        return Cache::has(self::STALE_KEY);
    }

    /**
     * Work the ranks out again if a score has moved since they last were.
     *
     * @return int|null how many games changed place, or null when nothing had moved
     */
    public function rebuildIfStale(): ?int
    {
        return $this->isStale() ? $this->rebuild() : null;
    }

    /** @return int how many games changed place */
    public function rebuild(): int
    {
        // Forgotten before rather than after, so a score that moves while
        // this runs marks them stale again for the next round.
        Cache::forget(self::STALE_KEY);

        try {
            return $this->rankAll();
        } catch (Throwable $e) {
            // Still stale, or the next round would find nothing to do and the
            // ranks would wait for an unrelated score to move.
            $this->markStale();

            throw $e;
        }
    }

    private function rankAll(): int
    {
        $changed = DB::update(<<<'SQL'
            UPDATE games
            JOIN (
                SELECT g.id, ROW_NUMBER() OVER (
                    ORDER BY g.rating DESC,
                        COALESCE(lb.votes, 0) DESC,
                        COALESCE(ra.num_distinct_players, 0) DESC,
                        g.title,
                        g.id
                ) AS position
                FROM games g
                LEFT JOIN launchbox_games lb ON lb.id = g.launchbox_id
                LEFT JOIN ra_games ra ON ra.id = g.retroachievements_id
                WHERE g.rating IS NOT NULL
            ) ranked ON ranked.id = games.id
            SET games.library_rank = ranked.position
            WHERE NOT (games.library_rank <=> ranked.position)
            SQL);

        return $changed + DB::table('games')
            ->whereNull('rating')
            ->whereNotNull('library_rank')
            ->update(['library_rank' => null]);
    }
}
