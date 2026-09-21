<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RaAchievement;
use App\Models\RaProgress;
use App\Support\RetroAchievements\ProgressTotals;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Owns the denormalised counters in ra_progress.
 *
 * Nothing else writes them. They are worked out from the unlock rows every
 * time, inside the transaction that stores them, so the summary and the detail
 * cannot drift apart — and a rebuild from scratch is the same code path as an
 * incremental update, which is what makes the two comparable in a test.
 */
class RetroAchievementsProgress
{
    /**
     * Store unlocks, keeping the earliest date we have ever seen.
     *
     * RetroAchievements sometimes reports a hardcore unlock dated earlier than
     * its softcore counterpart, and different endpoints disagree about the
     * same unlock. The rule is: fill a null, or move a date earlier, never
     * later. Done in SQL rather than read-modify-write in PHP so that it is
     * atomic and so that running the same payload twice changes nothing.
     *
     * Unlocks for achievements we have no row for are dropped and their games
     * returned, because that is not corruption — it is a set that grew since
     * we last synced it, and the caller should go and fetch it.
     *
     * @param  array<int, array{achievement_id: int, game_id: int, unlocked_at: string|null, unlocked_hardcore_at: string|null}>  $rows
     * @return array{written: int, unknown_games: array<int, int>}
     */
    public function writeUnlocks(int $userId, array $rows): array
    {
        if ($rows === []) {
            return ['written' => 0, 'unknown_games' => []];
        }

        $ids = array_values(array_unique(array_column($rows, 'achievement_id')));

        // ra_game_id is taken from our own achievement row, never from the
        // payload: the foreign key and the grouping index must agree, and only
        // one of the two sources is the one the key points at.
        $known = RaAchievement::query()->whereIn('id', $ids)->pluck('ra_game_id', 'id');

        $payload = [];
        $unknownGames = [];

        foreach ($rows as $row) {
            $gameId = $known->get($row['achievement_id']);

            if ($gameId === null) {
                $unknownGames[$row['game_id']] = $row['game_id'];

                continue;
            }

            $payload[] = [
                'user_id' => $userId,
                'ra_achievement_id' => $row['achievement_id'],
                'ra_game_id' => (int) $gameId,
                'unlocked_at' => $row['unlocked_at'],
                'unlocked_hardcore_at' => $row['unlocked_hardcore_at'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($payload, 250) as $chunk) {
            DB::table('ra_unlocks')->upsert($chunk, ['user_id', 'ra_achievement_id'], [
                // Both arms need the coalesce. least(x, NULL) is NULL, so the
                // obvious least(coalesce(old, new), new) wipes a stored date
                // whenever the incoming one is absent.
                'unlocked_at' => DB::raw(
                    'least(coalesce(ra_unlocks.unlocked_at, values(unlocked_at)),'
                    .' coalesce(values(unlocked_at), ra_unlocks.unlocked_at))'
                ),
                'unlocked_hardcore_at' => DB::raw(
                    'least(coalesce(ra_unlocks.unlocked_hardcore_at, values(unlocked_hardcore_at)),'
                    .' coalesce(values(unlocked_hardcore_at), ra_unlocks.unlocked_hardcore_at))'
                ),
                'updated_at' => DB::raw('values(updated_at)'),
            ]);
        }

        return ['written' => count($payload), 'unknown_games' => array_values($unknownGames)];
    }

    /**
     * Recount one person's progress in one set and store it.
     *
     * @param  array<string, mixed>  $extra  fields only the API knows, such as the award
     */
    public function recompute(int $userId, int $raGameId, array $extra = []): void
    {
        DB::transaction(function () use ($userId, $raGameId, $extra) {
            // There has to be a row before there is anything to lock, and two
            // jobs racing to create one would both insert and one would hit
            // the unique index. insertOrIgnore settles that without a failure.
            DB::table('ra_progress')->insertOrIgnore([
                'user_id' => $userId,
                'ra_game_id' => $raGameId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // The transaction's first read, and locking on purpose. A plain
            // SELECT under REPEATABLE READ fixes the snapshot here, and an
            // unlock committed by another job a moment later would be invisible
            // to the aggregate below — which would then write counters that are
            // already out of date. A locking read takes no snapshot.
            DB::table('ra_progress')
                ->where('user_id', $userId)
                ->where('ra_game_id', $raGameId)
                ->lockForUpdate()
                ->first();

            $totals = $this->totalsFor($userId, $raGameId);

            if ($totals === null) {
                // The set has never been downloaded, so every count would be
                // zero and the page would claim an identified game has no
                // achievements. Leave it stale for the set sync to fix.
                DB::table('ra_progress')
                    ->where('user_id', $userId)
                    ->where('ra_game_id', $raGameId)
                    ->update(['stale' => true, 'updated_at' => now()]);

                return;
            }

            DB::table('ra_progress')
                ->where('user_id', $userId)
                ->where('ra_game_id', $raGameId)
                ->update($totals->toArray() + $extra + [
                    'stale' => false,
                    'synced_at' => now(),
                    'updated_at' => now(),
                ]);
        });
    }

    /**
     * Recount every set this person has any unlock or progress row for.
     *
     * @return int how many rows were rewritten
     */
    public function recomputeAll(int $userId): int
    {
        $gameIds = DB::table('ra_unlocks')->where('user_id', $userId)->distinct()->pluck('ra_game_id')
            ->merge(DB::table('ra_progress')->where('user_id', $userId)->distinct()->pluck('ra_game_id'))
            ->unique();

        foreach ($gameIds as $gameId) {
            $this->recompute($userId, (int) $gameId);
        }

        return $gameIds->count();
    }

    /**
     * Flag every progress row for a set as needing a recount.
     *
     * Called when a set changes, because points_possible and the totals stop
     * being true the moment an achievement is added, demoted or repriced.
     */
    public function markStale(int $raGameId): int
    {
        return RaProgress::query()->where('ra_game_id', $raGameId)->update(['stale' => true]);
    }

    /**
     * Count up one person's progress from the unlock rows.
     *
     * Null when the set has no achievements stored, which is different from a
     * set where they have none: the first is our gap, the second is their
     * score.
     */
    public function totalsFor(int $userId, int $raGameId): ?ProgressTotals
    {
        $row = DB::table('ra_achievements as a')
            ->leftJoin('ra_unlocks as u', function ($join) use ($userId) {
                $join->on('u.ra_achievement_id', '=', 'a.id')->where('u.user_id', '=', $userId);
            })
            ->where('a.ra_game_id', $raGameId)
            // Unofficial and demoted achievements are stored but never count,
            // which is what RetroAchievements itself does — so a past mastery
            // keeps agreeing with what the site shows.
            ->where('a.core', true)
            ->whereNull('a.removed_at')
            ->selectRaw(<<<'SQL'
                count(a.id) as achievements_possible,
                coalesce(sum(a.points), 0) as points_possible,
                -- MariaDB has no COUNT(*) FILTER, so the condition goes in a
                -- SUM over a CASE.
                coalesce(sum(case when u.unlocked_at is not null then 1 else 0 end), 0) as unlocked_count,
                coalesce(sum(case when u.unlocked_hardcore_at is not null then 1 else 0 end), 0) as unlocked_hardcore_count,
                coalesce(sum(case when u.unlocked_at is not null then a.points else 0 end), 0) as points_earned,
                coalesce(sum(case when u.unlocked_hardcore_at is not null then a.points else 0 end), 0) as points_hardcore_earned,
                greatest(
                    coalesce(max(u.unlocked_at), max(u.unlocked_hardcore_at)),
                    coalesce(max(u.unlocked_hardcore_at), max(u.unlocked_at))
                ) as last_unlock_at
            SQL)
            ->first();

        if ($row === null || (int) $row->achievements_possible === 0) {
            return null;
        }

        return new ProgressTotals(
            unlockedCount: (int) $row->unlocked_count,
            unlockedHardcoreCount: (int) $row->unlocked_hardcore_count,
            achievementsPossible: (int) $row->achievements_possible,
            pointsEarned: (int) $row->points_earned,
            pointsHardcoreEarned: (int) $row->points_hardcore_earned,
            pointsPossible: (int) $row->points_possible,
            lastUnlockAt: $row->last_unlock_at !== null ? Carbon::parse($row->last_unlock_at) : null,
        );
    }

    /**
     * Turn one GetGameInfoAndUserProgress payload into unlock rows.
     *
     * A locked achievement has no DateEarned key at all rather than a null
     * one, so absence is the signal and Arr::get's default does the work.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array{achievement_id: int, game_id: int, unlocked_at: string|null, unlocked_hardcore_at: string|null}>
     */
    public function unlocksIn(array $payload): array
    {
        $gameId = (int) Arr::get($payload, 'ID', 0);

        return Collection::make((array) Arr::get($payload, 'Achievements', []))
            ->map(function ($achievement, $id) use ($gameId) {
                $earned = Arr::get((array) $achievement, 'DateEarned');
                $hardcore = Arr::get((array) $achievement, 'DateEarnedHardcore');

                if ($earned === null && $hardcore === null) {
                    return null;
                }

                return [
                    'achievement_id' => (int) (Arr::get((array) $achievement, 'ID') ?? $id),
                    'game_id' => $gameId,
                    // A hardcore unlock counts as a softcore one too, so a row
                    // that only carries the hardcore date still fills both.
                    'unlocked_at' => is_string($earned) ? $earned : (is_string($hardcore) ? $hardcore : null),
                    'unlocked_hardcore_at' => is_string($hardcore) ? $hardcore : null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
