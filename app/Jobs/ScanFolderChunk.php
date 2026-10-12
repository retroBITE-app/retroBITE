<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\LibraryScanner;
use App\Support\Console;
use App\Support\Scanning\ScanUnit;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Record one chunk of a scan's plan: its games and their file rows.
 *
 * One of the batch ScanConsoleFolder queues.
 */
class ScanFolderChunk implements ShouldQueue
{
    use Batchable, Queueable;

    /** A hundred games is seconds of writing; this is room for a slow disk. */
    public int $timeout = 600;

    /** One attempt, like the scan it belongs to. The next scan picks up whatever it missed. */
    public int $tries = 1;

    /**
     * @param  list<ScanUnit>  $units
     */
    public function __construct(
        public readonly string $console,
        public readonly array $units,
        public readonly int $number,
        public readonly int $of,
    ) {
        $this->onQueue('default');
    }

    public function handle(LibraryScanner $scanner): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $console = Console::tryFrom($this->console);

        if ($console === null) {
            return;
        }

        $result = $scanner->record($console, $this->units);

        Log::info('Scan chunk recorded.', [
            'console' => $console->key,
            'chunk' => "{$this->number}/{$this->of}",
            'games' => count($this->units),
            'files_seen' => $result->filesSeen,
            'games_created' => $result->gamesCreated,
            'files_created' => $result->filesCreated,
            'files_returned' => $result->filesReturned,
        ]);
    }
}
