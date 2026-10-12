<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Support\Console;
use App\Support\LibraryStorage;
use App\Support\Scanning\FolderCounts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The last step of a scan, once every chunk has finished or failed.
 *
 * Starts what a scan exists to start: a lookup for every game still waiting
 * for one.
 */
class FinishScan implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $console,
        public readonly int $failedChunks = 0,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $console = Console::tryFrom($this->console);

        if ($console === null) {
            return;
        }

        // The scan has just walked the folder, so the card's count is the
        // older answer.
        FolderCounts::recount($console);

        // Recording what is on disk is only half of it. Without this the
        // library fills with placeholders named after their filenames and
        // nothing ever identifies them.
        $queued = MatchGame::queueAwaiting($console->key);

        // And what the provider cannot answer, the console might. Zero for
        // every console without a toolbox, which is nearly all of them.
        $inspecting = InspectGameFile::queueAwaiting($console->key);

        $context = [
            'console' => $console->key,
            'failed_chunks' => $this->failedChunks,
            'queued_for_lookup' => $queued,
            'queued_for_inspection' => $inspecting,
        ];

        $this->failedChunks > 0
            ? Log::warning('Scan finished with failed chunks.', $context)
            : Log::info('Scan finished.', $context);

        // A scan is the moment the app learns what arrived over the share,
        // which is what the disk figure has no other way of hearing about.
        LibraryStorage::changed();
    }
}
