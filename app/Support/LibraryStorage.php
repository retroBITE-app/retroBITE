<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Scanning\LibraryFolders;
use FilesystemIterator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * How much room the game library takes, and how much it has left to grow into.
 *
 * Deliberately not the device's own used figure. A retroBITE host is usually
 * somebody's desktop, where the disk is mostly an operating system and years of
 * other things: reporting that back says the drive is 84 % full and says
 * nothing whatever about the library, which was 7 % of it. The number worth
 * showing is what the games occupy.
 *
 * The denominator is that plus whatever is actually free, so the bar still
 * fills as the disk does — it reaches 100 % exactly when there is no room left,
 * whatever it was that filled the drive. A library measured against the whole
 * device would sit near zero on a full disk and warn nobody.
 *
 * Measured rather than summed from `game_files`. The database knows only what a
 * scan has imported: not the artwork and configs the loader exports write
 * beside the ROMs, not a BIOS, and nothing at all copied in over the share
 * since. Walking is the only way to answer for what is really on the disk, and
 * it is why this is the one reading here that is cached.
 */
final class LibraryStorage
{
    private const KEY = 'library.storage';

    /** As FolderCounts: short, because an upload or an export moves it. */
    private const TTL = 60;

    /** How long a stale reading may still be served while a fresh one is taken. */
    private const STALE = 300;

    /**
     * @param  int  $used  bytes the library folder occupies
     * @param  int  $free  bytes still free on the device it sits on
     */
    public function __construct(
        public readonly int $used,
        public readonly int $free,
    ) {}

    /**
     * What the library takes and what is left, or null when it cannot be read.
     *
     * Null rather than zeros, as ScreenScraperQuota does: a share that is not
     * mounted and an empty library are not the same answer, and only one of
     * them should draw a bar.
     *
     * Cache::flexible() rather than remember(), for the reason FolderCounts
     * gives: a remember() that has just expired makes one unlucky visitor pay
     * for walking the whole library inside their own request, and the sidebar
     * asks again every minute on every open tab.
     */
    public static function current(): ?self
    {
        $reading = Cache::flexible(self::KEY, [self::TTL, self::STALE], function (): ?array {
            $measured = self::measure(LibraryFolders::root());

            return $measured === null ? null : ['used' => $measured->used, 'free' => $measured->free];
        });

        if (! is_array($reading)) {
            return null;
        }

        return new self(used: $reading['used'], free: $reading['free']);
    }

    /** Forget the reading, after anything that moves a lot of bytes. */
    public static function forget(): void
    {
        Cache::forget(self::KEY);
    }

    /** What the library could grow to without anything else being deleted. */
    public function total(): int
    {
        return $this->used + $this->free;
    }

    /**
     * Walk one library root, or answer null where it cannot be measured.
     *
     * disk_free_space() announces an unreadable path twice: a warning, and
     * false. The warning is the dangerous one — Laravel's bootstrapped handler
     * promotes a plain E_WARNING into a thrown ErrorException, so a games mount
     * that is not there would take down every page in the application rather
     * than blank one figure in the sidebar. Silenced for exactly that call the
     * way CoverArt::encode() does it, rather than with `@`, which reaches it
     * only because the handler happens to consult error_reporting().
     */
    private static function measure(string $path): ?self
    {
        if ($path === '' || ! is_dir($path)) {
            return null;
        }

        set_error_handler(static function (): bool {
            return true;
        });

        try {
            $free = disk_free_space($path);
        } finally {
            restore_error_handler();
        }

        if ($free === false) {
            return null;
        }

        return new self(used: self::bytesUnder($path), free: (int) $free);
    }

    /**
     * Every byte under the library root, artwork and configs included.
     *
     * Not filtered by layout, unlike FolderCounts. That one answers how many
     * games are here and has to ignore OPL's ART and CFG folders to do it; this
     * one answers how much room the library takes, and those folders take some.
     */
    private static function bytesUnder(string $root): int
    {
        $bytes = 0;

        try {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );

            foreach ($files as $file) {
                // Symlinks are stepped over rather than followed: a link back
                // into the tree would count its target twice, and one pointing
                // out of it would count something that is not the library.
                if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->isLink()) {
                    continue;
                }

                $bytes += $file->getSize();
            }
        } catch (Throwable $e) {
            // An unreadable subtree part way through is worth saying out loud,
            // and worth keeping the bytes already counted for.
            Log::warning('Could not measure the library folder.', [
                'root' => $root,
                'reason' => $e->getMessage(),
            ]);
        }

        return $bytes;
    }
}
