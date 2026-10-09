<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

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
 * shifts everything between its old place and its new one.
 */
final class LibraryRanking
{
    /** @return int how many games changed place */
    public function rebuild(): int
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
