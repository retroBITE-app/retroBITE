<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RetroAchievementsStatus;
use App\Models\Game;
use App\Models\RaGameHash;
use App\Support\RetroAchievements\FileFingerprint;
use App\Support\RetroAchievements\IdentifyOutcome;
use App\Support\RetroAchievements\IdentifyResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Works out which achievement set a game is, locally.
 *
 * No request is made here. The hash index is downloaded a console at a time
 * ahead of need, so identifying twenty thousand games costs twenty thousand
 * index lookups and no API calls at all.
 */
class RetroAchievementsMatcher
{
    /**
     * Identify one game, hashing first if the cached hash is stale or absent.
     */
    public function identify(Game $game): IdentifyResult
    {
        $console = $game->console();

        if ($console === null || $console->retroachievementsId === null) {
            return $this->settle($game, IdentifyResult::unsupported(
                $game,
                'Console is not mapped to RetroAchievements.',
            ));
        }

        $file = $game->hashableFile();

        if ($file === null) {
            return $this->settle($game, IdentifyResult::unsupported(
                $game,
                'No file RAHasher can read.',
            ));
        }

        $fingerprint = FileFingerprint::of($file);

        if ($fingerprint === null) {
            // Skipped, not Unsupported. A file that has gone is nearly always
            // a disk that is not mounted, and a terminal answer here would
            // need undoing by hand for every game on that disk.
            Log::info('Skipped RetroAchievements identification; the file is not on disk.', [
                'game' => $game->id,
                'console' => $game->console,
                'path' => $file->path,
            ]);

            return IdentifyResult::skipped($game, 'The file is not on disk.');
        }

        if ($file->ra_hash === null || ! $fingerprint->matches($file->ra_hash_size, $file->ra_hash_mtime)) {
            // Hashing a disc image is minutes of reading, so it happens on its
            // own queue and this comes back once there is something to look up.
            return IdentifyResult::needsHash($game, $file);
        }

        $raGameId = RaGameHash::query()
            ->where('hash', $file->ra_hash)
            ->where('ra_console_id', $console->retroachievementsId)
            ->value('ra_game_id');

        if ($raGameId === null) {
            return $this->settle($game, IdentifyResult::noMatch($game, $file->ra_hash));
        }

        return $this->settle($game, IdentifyResult::matched($game, (int) $raGameId, $file->ra_hash));
    }

    /**
     * Try every unidentified game on a console against the index again.
     *
     * Pure SQL and no hashing: the hashes are already cached on the files, so
     * a game that had no set in January is identified the night the set
     * appears, without anybody touching it and without reading a byte.
     *
     * @return int how many were identified
     */
    public function rematchConsole(string $consoleKey, int $raConsoleId): int
    {
        $pairs = DB::table('games')
            ->join('game_files', 'game_files.game_id', '=', 'games.id')
            ->join('ra_game_hashes', function ($join) use ($raConsoleId) {
                $join->on('ra_game_hashes.hash', '=', 'game_files.ra_hash')
                    ->where('ra_game_hashes.ra_console_id', '=', $raConsoleId);
            })
            ->where('games.console', $consoleKey)
            ->whereNull('games.retroachievements_id')
            ->whereIn('games.retroachievements_status', [
                RetroAchievementsStatus::Pending->value,
                RetroAchievementsStatus::NoMatch->value,
            ])
            // A game can hold several hashed files and a set lists a hash per
            // disc, so the join multiplies. Grouping collapses it back to one
            // answer per game; min() only decides between duplicates of the
            // same set, so which one wins does not matter.
            ->groupBy('games.id')
            ->selectRaw('games.id as game_id, min(ra_game_hashes.ra_game_id) as ra_game_id')
            ->get();

        foreach ($pairs as $pair) {
            $game = Game::find((int) $pair->game_id);

            if ($game === null) {
                continue;
            }

            $this->apply($game, (int) $pair->ra_game_id);

            Log::info('RetroAchievements set appeared for a game that had none.', [
                'game' => $game->id,
                'console' => $game->console,
                'ra_game_id' => (int) $pair->ra_game_id,
            ]);
        }

        return $pairs->count();
    }

    /**
     * Record the outcome on the game, and say so in the log.
     *
     * Every transition is logged with the game, console and hash, because the
     * failure this integration can have is a quiet one: a whole console going
     * NoMatch because its id is wrong looks exactly like a console with no
     * sets, unless somebody wrote down which hash was asked about.
     */
    private function settle(Game $game, IdentifyResult $result): IdentifyResult
    {
        $status = match ($result->outcome) {
            IdentifyOutcome::Matched => RetroAchievementsStatus::Matched,
            IdentifyOutcome::NoMatch => RetroAchievementsStatus::NoMatch,
            IdentifyOutcome::Unsupported => RetroAchievementsStatus::Unsupported,
            default => $game->retroachievements_status,
        };

        if ($result->outcome === IdentifyOutcome::Skipped) {
            return $result;
        }

        if ($result->raGameId !== null) {
            $this->apply($game, $result->raGameId);
        } else {
            $game->update(['retroachievements_status' => $status]);
        }

        Log::info('RetroAchievements identification finished.', [
            'game' => $game->id,
            'console' => $game->console,
            'hash' => $result->hash,
            'status' => $status->value,
            'ra_game_id' => $result->raGameId,
            'reason' => $result->reason,
        ]);

        return $result;
    }

    private function apply(Game $game, int $raGameId): void
    {
        $game->update([
            'retroachievements_id' => $raGameId,
            'retroachievements_status' => RetroAchievementsStatus::Matched,
            'retroachievements_matched_at' => now(),
        ]);
    }
}
