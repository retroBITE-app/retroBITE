<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\ScanAborted;
use App\Services\LibraryScanner;
use App\Support\Console;
use App\Support\LibraryStorage;
use App\Support\Scanning\FolderCounts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

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
        } finally {
            // Whatever the scan found, it has just walked the folder, so the
            // cards' count is the older answer. Counted again even on an
            // abort: a drive that has gone away should stop reporting the
            // number it had before it did.
            FolderCounts::recount($console);
        }

        // Recording what is on disk is only half of it. Without this the
        // library fills with placeholders named after their filenames and
        // nothing ever identifies them.
        $queued = MatchGame::queueAwaiting($console->key);

        // And what the provider cannot answer, the console might. Zero for
        // every console without a toolbox, which is nearly all of them.
        $inspecting = InspectGameFile::queueAwaiting($console->key);

        Log::info('Scan finished.', $result->toArray() + [
            'queued_for_lookup' => $queued,
            'queued_for_inspection' => $inspecting,
        ]);

        // A scan is the moment the app learns what arrived over the share,
        // which is what the disk figure has no other way of hearing about.
        LibraryStorage::changed();
    }
}
