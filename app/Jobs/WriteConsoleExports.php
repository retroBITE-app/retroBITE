<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Support\Console;
use App\Support\ExportProgress;
use App\Tools\ConsoleTools;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Write a loader's own files back into a console's folder.
 *
 * The only thing in retroBITE that writes to somebody's game library, and it
 * never runs by itself: a scan does not trigger it and neither does a match.
 * Somebody asks for it, having been told which directory it will write to.
 *
 * One attempt. A half-finished export is harmless — every writer here skips
 * what it has already written — but a retry after a permissions failure only
 * puts the same line in the log twice.
 */
class WriteConsoleExports implements ShouldQueue
{
    use Queueable;

    /** Reading and re-encoding a few hundred covers is minutes, not seconds. */
    public int $timeout = 1800;

    public int $tries = 1;

    /**
     * @param  string  $export  'cfg' or 'art', as the toolbox's exports() names them
     */
    public function __construct(
        public readonly string $console,
        public readonly string $export,
        public readonly bool $force = false,
    ) {
        // Disk work, like hashing. Not the scraper queue, which exists to pace
        // provider requests and would sit blocked behind a library of covers.
        $this->onQueue('media');
    }

    public function handle(): void
    {
        $console = Console::tryFrom($this->console);

        if ($console === null) {
            return;
        }

        $tools = ConsoleTools::for($console);

        if ($tools === null || ! in_array($this->export, $tools->exports(), true)) {
            Log::warning('An export was asked of a console that does not offer it.', [
                'console' => $this->console,
                'export' => $this->export,
            ]);

            return;
        }

        try {
            $counts = $tools->export($this->export)
                ->force($this->force)
                ->onProgress($this->reporter())
                ->run();
        } finally {
            // However this ends, the page stops being told an export is running.
            ExportProgress::finish($console->key, $this->export);
        }

        Log::info('Export finished.', $counts + [
            'console' => $console->key,
            'export' => $this->export,
        ]);
    }

    /**
     * What the toolbox calls after each game, so the library page can watch.
     *
     * Throttled to twice a second: a library of six hundred discs would
     * otherwise write six hundred rows to the cache table to move a bar that
     * nobody is reading faster than it renders. The first and last counts
     * always go through, so a bar starts at zero and ends full.
     *
     * @return callable(int, int): void
     */
    private function reporter(): callable
    {
        $last = 0.0;

        return function (int $done, int $total) use (&$last): void {
            $now = microtime(true);

            if ($done !== 0 && $done !== $total && $now - $last < 0.5) {
                return;
            }

            $last = $now;

            ExportProgress::advance($this->console, $this->export, $done, $total);
        };
    }
}
