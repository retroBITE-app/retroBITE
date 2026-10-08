<?php

declare(strict_types=1);

namespace App\Jobs\RetroAchievements;

use App\Enums\RetroAchievementsStatus;
use App\Exceptions\RetroAchievements\HasherUnavailable;
use App\Exceptions\RetroAchievements\HashFailed;
use App\Exceptions\RetroAchievements\RetroAchievementsException;
use App\Models\Game;
use App\Services\RetroAchievementsHasher;
use App\Support\RetroAchievements\FileFingerprint;
use App\Support\Scanning\LibraryFolders;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Compute a game's RetroAchievements hash.
 *
 * On its own queue and its own connection. The jobs table has no connection
 * column — a job is re-reserved after its connection's retry_after by
 * whichever worker popped it — so a long job is kept safe by its queue name,
 * not by which connection put it there. Left on a 90-second connection, a
 * CHD taking four minutes would be handed to a second worker mid-read and
 * hashed twice at once.
 */
class HashGame implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    /** One retry, for a disk that was briefly busy. A bad file fails outright. */
    public int $tries = 2;

    /** Just past the timeout, so a finished job never blocks the next one. */
    public int $uniqueFor = 1900;

    public function __construct(public readonly int $gameId)
    {
        $this->onConnection('database-long')->onQueue('ra-hash');
    }

    public function uniqueId(): string
    {
        return 'ra-hash:'.$this->gameId;
    }

    public function handle(RetroAchievementsHasher $hasher): void
    {
        $game = Game::find($this->gameId);

        if ($game === null) {
            return;
        }

        $console = $game->console();
        $file = $game->hashableFile();

        if ($console?->retroachievementsId === null || $file === null) {
            return;
        }

        $fingerprint = FileFingerprint::of($file);

        if ($fingerprint === null) {
            Log::info('Skipped RA hashing a file that is no longer there.', [
                'game' => $game->id,
                'path' => $file->path,
            ]);

            return;
        }

        // First thing in handle() rather than inside the hasher, because this
        // is what makes the job safe to dispatch more than once: every
        // ScreenScraper retry that ends Unmatched dispatches the RA chain
        // again, and reading a four-gigabyte image to learn what we already
        // know is the one cost worth never paying twice.
        if ($file->ra_hash !== null && $fingerprint->matches($file->ra_hash_size, $file->ra_hash_mtime)) {
            return;
        }

        $path = LibraryFolders::pathOf($file);

        try {
            $hash = $hasher->hash($console->retroachievementsId, $path);
        } catch (HashFailed $e) {
            // About this file, so it is recorded against the game.
            $game->update(['retroachievements_status' => RetroAchievementsStatus::HashFailed]);

            Log::warning('RAHasher could not hash a file.', [
                'game' => $game->id,
                'console' => $game->console,
                'path' => $file->path,
                'reason' => $e->getMessage(),
            ]);

            $this->failOrThrow($e);

            return;
        } catch (HasherUnavailable $e) {
            // Says nothing about the file. Marking the game would put the whole
            // library into HashFailed over one bad deployment, and getting back
            // out would mean a manual reset of every row.
            Log::error('RAHasher is not available.', ['game' => $game->id, 'reason' => $e->getMessage()]);

            $this->failOrThrow($e);

            return;
        }

        $file->update([
            'ra_hash' => $hash,
            'ra_hash_size' => $fingerprint->size,
            'ra_hash_mtime' => $fingerprint->mtime,
            'ra_hashed_at' => now(),
        ]);

        Log::info('RA hash computed.', [
            'game' => $game->id,
            'console' => $game->console,
            'hash' => $hash,
        ]);
    }

    /**
     * Fail the job, or rethrow when there is no job to fail.
     *
     * Run straight from a command rather than off a queue, fail() has nothing
     * to act on and swallows the reason — the command would report success.
     */
    private function failOrThrow(RetroAchievementsException $e): void
    {
        if ($this->job === null) {
            throw $e;
        }

        $this->fail($e);
    }
}
