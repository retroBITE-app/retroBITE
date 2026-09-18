<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\ScanAborted;
use App\Services\LibraryScanner;
use App\Support\Console;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Walk one console's folder and record what is on it.
 *
 * Queued rather than run in the request: a library on a spinning disk takes
 * minutes to walk, and the interface should be showing rows while it happens.
 *
 * Deliberately not on the scraper queue. Nothing here talks to ScreenScraper —
 * scanning is disk work, and pinning it behind the single scraper worker would
 * make a scan wait on whatever lookup happened to be in flight.
 */
class ScanConsoleFolder implements ShouldQueue
{
    use Queueable;

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

    public function handle(LibraryScanner $scanner): void
    {
        $console = Console::tryFrom($this->console);

        if ($console === null) {
            Log::warning('Scan requested for an unknown console.', ['console' => $this->console]);

            return;
        }

        try {
            $result = $scanner->scan($console);
        } catch (ScanAborted $e) {
            // Not a crash: the scan refused to run on a folder it did not
            // trust, which is the correct outcome and worth surfacing rather
            // than retrying.
            Log::warning('Scan aborted.', ['console' => $console->key, 'reason' => $e->getMessage()]);

            return;
        }

        // Recording what is on disk is only half of it. Without this the
        // library fills with placeholders named after their filenames and
        // nothing ever identifies them.
        $queued = MatchGame::queueAwaiting($console->key);

        Log::info('Scan finished.', $result->toArray() + ['queued_for_lookup' => $queued]);
    }
}
