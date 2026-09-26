<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\GameFile;
use App\Support\Console;
use App\Tools\ConsoleTools;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Ask a console's toolbox what one of its files says about itself.
 *
 * A PlayStation 2 disc carries the serial Open PS2 Loader keys its artwork and
 * its per-game config on, and no provider will hand that over. Reading it means
 * opening the image, which is why this is queued, and on the toolbox's own queue
 * beside the loader exports: the single scraper worker exists to pace provider
 * requests rather than to sit blocked behind a disc image, and on media it would
 * wait behind every artwork download a scan queues.
 *
 * Deliberately not chained to identification. ScreenScraper has no serial
 * parameter, so nothing learned here can improve a lookup, and coupling the two
 * would put a four-gigabyte read behind the scraper queue for no gain.
 */
class InspectGameFile implements ShouldQueue
{
    use Queueable;

    /**
     * A serial the header does not carry means reading the whole image, and the
     * disk is often a spinning one over USB.
     */
    public int $timeout = 900;

    public int $tries = 2;

    public function __construct(
        public readonly int $fileId,
        public readonly bool $force = false,
    ) {
        $this->onQueue('toolbox');
    }

    public function handle(): void
    {
        $file = GameFile::find($this->fileId);

        if ($file === null || ! $file->isPresent()) {
            return;
        }

        // Already read, and a disc's serial does not change. Re-reading every
        // scan would put the whole library through the disk again for nothing.
        if ($file->license_id !== null && ! $this->force) {
            return;
        }

        $console = Console::tryFrom($file->game->console);

        if ($console === null) {
            return;
        }

        $tools = ConsoleTools::for($console);

        // The ordinary case for 134 of the 135 consoles.
        if ($tools === null || ! $tools->handles($file)) {
            return;
        }

        $facts = $tools->inspect($file);

        if ($facts === []) {
            Log::info('A console tool found nothing in a file.', [
                'console' => $console->key,
                'path' => $file->path,
            ]);

            return;
        }

        $file->update(array_filter([
            'license_id' => Arr::get($facts, 'license_id'),
            'video_mode' => Arr::get($facts, 'video_mode'),
            // Only where nothing knows better already: the provider's region is
            // the more precise of the two, and a serial can only say which of
            // five territories pressed the disc.
            'region' => $file->region ?? Arr::get($facts, 'region'),
        ]));
    }

    /**
     * Queue an inspection for every file on a console that has not had one.
     *
     * Returns how many were queued, which is zero for a console with no
     * toolbox — the common case, and worth not logging as an event.
     */
    public static function queueAwaiting(string $console, bool $force = false): int
    {
        $consoleObject = Console::tryFrom($console);

        if ($consoleObject === null || ConsoleTools::for($consoleObject) === null) {
            return 0;
        }

        $files = GameFile::query()
            ->whereHas('game', function ($query) use ($console): void {
                $query->where('console', $console);
            })
            ->identifiable()
            ->present()
            ->when(! $force, function ($query): void {
                $query->uninspected();
            })
            ->pluck('id');

        foreach ($files as $id) {
            self::dispatch($id, $force);
        }

        return $files->count();
    }
}
