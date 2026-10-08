<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\GameUpdated;
use App\Models\GameFile;
use App\Support\LiveUpdates;
use App\Support\Scanning\Checksums;
use App\Support\Scanning\LibraryFolders;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Compute a file's checksums, on demand.
 *
 * On its own queue and its own connection, for two separate reasons. Reading
 * four gigabytes is disk work, and the single scraper worker exists to pace
 * provider requests rather than sit blocked behind a disc image — that is why
 * it is not on the scraper queue.
 *
 * And the jobs table has no connection column: a job is re-reserved after the
 * retry_after of whichever connection's worker popped it, so isolation is by
 * queue name alone. On the 90-second default this job, with its hour-long
 * timeout, was handed to a second worker four minutes in and the same image
 * was read twice at once.
 */
class HashFile implements ShouldQueue
{
    use Queueable;

    /** A slow disk reading a dual-layer image takes a while. */
    public int $timeout = 3600;

    public int $tries = 2;

    public function __construct(public readonly int $fileId)
    {
        $this->onConnection('database-long')->onQueue('hash');
    }

    public function handle(): void
    {
        $file = GameFile::find($this->fileId);

        if ($file === null || $file->missing_since !== null) {
            return;
        }

        $path = LibraryFolders::pathOf($file);

        if (! is_file($path)) {
            // Gone since the last scan. The next scan will mark it missing;
            // failing the job would only retry the same absence.
            Log::info('Skipped hashing a file that is no longer there.', ['path' => $file->path]);

            return;
        }

        try {
            $sums = Checksums::of($path);
        } catch (Throwable $e) {
            Log::warning('Hashing failed.', ['path' => $file->path, 'reason' => $e->getMessage()]);

            throw $e;
        }

        $file->update([
            'crc' => $sums->crc,
            'md5' => $sums->md5,
            'sha1' => $sums->sha1,
            // Recorded from the same read, so it cannot disagree with the
            // hashes the way a separately stat'ed size could.
            'size_bytes' => $sums->size,
            'hashed_at' => now(),
        ]);

        // The manual identify form waits on these to ask by checksum.
        LiveUpdates::game($file->game_id, GameUpdated::HASHED);
    }

    /**
     * The last try has gone, so the form waiting on the checksums hears it
     * now rather than when its timer runs out.
     */
    public function failed(?Throwable $e): void
    {
        $gameId = GameFile::query()->whereKey($this->fileId)->value('game_id');

        if ($gameId !== null) {
            LiveUpdates::game((int) $gameId, GameUpdated::FAILED);
        }
    }
}
