<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\ScanAborted;
use App\Services\LibraryScanner;
use App\Support\Console;
use App\Support\Scanning\FolderCounts;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The first step of a scan: walk the folder, plan it, hand it out in chunks.
 *
 * Writes no games. It works out what the folder holds (LibraryScanner::plan),
 * flags what has gone, and queues a batch of ScanFolderChunk jobs that record
 * the rest a hundred games at a time, then FinishScan. Each is a job on
 * `default`, so the sidebar's Scanning row counts them down.
 */
class ScanConsoleFolder implements ShouldQueue
{
    use Queueable;

    /**
     * Games per chunk. Small enough that a chunk is seconds of work; large
     * enough that a console of thirty thousand files is not a thousand jobs.
     */
    public const CHUNK = 100;

    /**
     * Walking a large library is slow; the default 60 seconds is not enough.
     */
    public int $timeout = 1800;

    /**
     * One attempt. A scan that failed on a missing mount will fail the same
     * way a second later, and a retry only puts the same message in the log
     * twice.
     */
    public int $tries = 1;

    public function __construct(public readonly string $console)
    {
        $this->onQueue('default');
    }

    /** The batch's name, which is also how a running scan of a console is found. */
    public static function batchName(Console $console): string
    {
        return "Scan {$console->key}";
    }

    public function handle(LibraryScanner $scanner): void
    {
        $console = Console::tryFrom($this->console);

        if ($console === null) {
            Log::warning('Scan requested for an unknown console.', ['console' => $this->console]);

            return;
        }

        // Two scans of one console at once would plan the same files into two
        // batches and race each other to write them.
        $running = DB::table('job_batches')
            ->where('name', self::batchName($console))
            ->whereNull('finished_at')
            ->whereNull('cancelled_at')
            ->exists();

        if ($running) {
            Log::info('Scan already running; not starting another.', ['console' => $console->key]);

            return;
        }

        try {
            $plan = $scanner->plan($console);
        } catch (ScanAborted $e) {
            // Not a crash: the scan refused to run on a folder it did not
            // trust, which is the correct outcome and worth surfacing rather
            // than retrying.
            Log::warning('Scan aborted.', ['console' => $console->key, 'reason' => $e->getMessage()]);

            // Counted again even on an abort: a drive that has gone away
            // should stop reporting the number it had before it did.
            FolderCounts::recount($console);

            return;
        }

        $chunks = $plan->chunks(self::CHUNK);

        Log::info('Scan planned.', [
            'console' => $console->key,
            'files' => $plan->fileCount(),
            'games' => count($plan->units),
            'chunks' => count($chunks),
            'files_missing' => $scanner->markMissing($console, $plan),
            'files_skipped' => $plan->skipped,
        ]);

        // Everything skipped: nothing to record, but the scan still ends the
        // way every other does.
        if ($chunks === []) {
            dispatch(new FinishScan($console->key));

            return;
        }

        $key = $console->key;

        Bus::batch(array_map(
            fn (array $units, int $index): ScanFolderChunk => new ScanFolderChunk($key, $units, $index + 1, count($chunks)),
            $chunks,
            array_keys($chunks),
        ))
            ->name(self::batchName($console))
            ->allowFailures()
            ->finally(function (Batch $batch) use ($key): void {
                dispatch(new FinishScan($key, $batch->failedJobs));
            })
            ->onQueue('default')
            ->dispatch();
    }
}
