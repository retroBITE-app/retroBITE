<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Decryption\DiscKeys;
use App\Enums\FileContent;
use App\Events\GameUpdated;
use App\Events\SystemUpdated;
use App\Models\GameFile;
use App\Support\Console;
use App\Support\LiveUpdates;
use App\Support\RomRegions;
use App\Support\Scanning\LibraryFolders;
use App\Tools\ConsoleTools;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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
 *
 * On the PS3 it is also the whole of checking a disc for Tools → Decrypt: is it
 * decrypted, and if not, does the key beside it fit. One per file at a time, so
 * a second Check while the first is waiting is the same job.
 */
class InspectGameFile implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * A serial the header does not carry means reading the whole image, and the
     * disk is often a spinning one over USB.
     */
    public int $timeout = 900;

    public int $tries = 2;

    /** As long as one can run, so a lock left by a dead worker frees itself. */
    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $fileId,
        public readonly bool $force = false,
    ) {
        $this->onQueue('toolbox');
    }

    /** One inspection per file waiting at a time, forced or not. */
    public function uniqueId(): string
    {
        return 'inspect:'.$this->fileId;
    }

    public function handle(): void
    {
        $file = GameFile::find($this->fileId);

        if ($file === null || ! $file->isPresent()) {
            return;
        }

        // Already read, and a disc's serial does not change. Re-reading every
        // scan would put the whole library through the disk again for nothing.
        if ($file->meta?->license_id !== null && ! $this->force) {
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

        $file->rememberMeta([
            ...array_filter([
                'license_id' => Arr::get($facts, 'license_id'),
                'video_mode' => Arr::get($facts, 'video_mode'),
                'disc_key' => Arr::get($facts, 'disc_key'),
            ]),
            // Outside the filter: false is the answer worth keeping.
            ...(Arr::has($facts, 'encrypted') ? ['encrypted' => (bool) Arr::get($facts, 'encrypted')] : []),
        ]);

        // Only where nothing knows better already: the provider's region is
        // the more precise of the two, and a serial can only say which of
        // five territories pressed the disc. Stored as the provider's code,
        // as every other region on a file is.
        $region = $file->region ?? RomRegions::fromName(Arr::get($facts, 'region'));

        // What the disc says it holds wins over what its name said.
        $content = FileContent::tryFrom((string) Arr::get($facts, 'content_type'));

        $file->update(array_filter([
            'region' => $region !== $file->region ? $region : null,
            'content_type' => $content !== $file->content_type ? $content : null,
        ]));

        LiveUpdates::game($file->game_id, GameUpdated::INSPECTED);
        LiveUpdates::soon(SystemUpdated::INSPECTED);
    }

    /**
     * Queue a fresh inspection of each file, read already or not: a Check from
     * Tools → Decrypt or a game list. Returns how many were queued.
     *
     * On the scan's queue rather than the toolbox's: a decryption check reads a
     * few sectors, belongs with reading the folder, and shows under Scanning in
     * the sidebar's Activity rather than behind a loader export.
     *
     * @param  iterable<int>  $fileIds
     */
    public static function queueFor(iterable $fileIds): int
    {
        $count = 0;

        foreach ($fileIds as $id) {
            self::dispatch($id, true)->onQueue('default');
            $count++;
        }

        return $count;
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

        if ($consoleObject === null) {
            return 0;
        }

        // Only what the toolbox reads (handles()): the CD consoles have one to
        // lay out folders and read nothing, and every one of their files
        // queued a job that did nothing, after every scan.
        $extensions = array_map('strtolower', $consoleObject->toolboxFileExtensions);

        if ($extensions === [] || ConsoleTools::for($consoleObject) === null) {
            return 0;
        }

        $files = GameFile::query()
            ->onConsole($console)
            ->identifiable()
            ->present()
            ->whereIn('extension', $extensions)
            ->when(! $force, function ($query): void {
                $query->uninspected();
            })
            ->pluck('id');

        foreach ($files as $id) {
            self::dispatch($id, $force);
        }

        $stale = $force ? 0 : self::queueStale($consoleObject, $extensions, $files->all());

        return $files->count() + $stale;
    }

    /**
     * Files read before that are worth reading again: an encrypted disc with a key
     * file beside it and none on record — a .dkey dropped in over the share since,
     * proven or shown as wrong before anyone presses Decrypt — and, where the
     * toolbox can tell, one whose content is still unknown.
     *
     * @param  array<int, string>  $extensions
     * @param  array<mixed>  $queued  ids already queued by the caller
     */
    private static function queueStale(Console $console, array $extensions, array $queued): int
    {
        $keys = app(DiscKeys::class);
        $readsContent = ConsoleTools::for($console)?->readsContent() ?? false;

        $files = GameFile::query()
            ->onConsole($console->key)
            ->present()
            ->whereIn('extension', $extensions)
            ->whereKeyNot($queued)
            ->whereHas('meta')
            ->with('meta')
            ->get()
            ->filter(function (GameFile $file) use ($keys, $readsContent): bool {
                if ($readsContent && $file->content_type === null) {
                    return true;
                }

                return $file->meta?->encrypted === true
                    && $file->meta->disc_key === null
                    && $keys->keyFiles(LibraryFolders::pathOf($file)) !== [];
            });

        return self::queueFor($files->modelKeys());
    }
}
