<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ConsoleSourceFolder;
use App\Services\DocLibrary;
use App\Support\Console;
use App\Support\LibraryStorage;
use App\Support\Scanning\FolderCounts;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Count the files in the library's console folders, read the free space, and
 * check the documents index against the docs folder.
 *
 * The only thing that reads the library disk for the figures on the consoles
 * page, the shelf, the dashboard and the sidebar: every one of them reads what
 * this left behind, so no page does disk work. Run every fifteen minutes by the
 * schedule, after a scan, when a console is added or changes layout, and from
 * the button on the consoles page.
 *
 * Without a console it is the whole library, and it does no walking itself: it
 * takes the free space and queues one job per console. A single job walking
 * every folder could outlast the default connection's retry_after of 90
 * seconds on a slow disk and be handed to a second worker while still running.
 *
 * Unique per console, and for the whole library, so pressing the button five
 * times or a schedule tick landing on a scan's recount queues one of each.
 */
class MeasureLibrary implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Under the default connection's retry_after, for the reason above. */
    public int $timeout = 80;

    public int $tries = 1;

    /** Long enough to cover a queue backed up behind a scan. */
    public int $uniqueFor = 900;

    public function __construct(public readonly ?string $console = null)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return $this->console ?? 'library';
    }

    public function handle(): void
    {
        if ($this->console === null) {
            LibraryStorage::measureFree();
            LibraryStorage::changed();

            foreach (ConsoleSourceFolder::consoles() as $console) {
                self::dispatch($console->key);
            }

            // The documents index, for a file changed in the docs folder
            // outside the app: pages read the index without looking.
            app(DocLibrary::class)->verify();

            return;
        }

        $console = Console::tryFrom($this->console);

        // Taken out of the library, or out of config, since this was queued.
        if ($console === null || ! ConsoleSourceFolder::has($console)) {
            return;
        }

        ConsoleSourceFolder::recordCount($console, FolderCounts::measure($console));

        // The consoles page and the shelf show this count, and listen for the
        // same signal as the disk figure: what is on the library disk changed.
        LibraryStorage::changed();
    }
}
