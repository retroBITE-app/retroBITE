<?php

declare(strict_types=1);

namespace App\Support\Scanning;

use App\Jobs\MeasureLibrary;
use App\Models\ConsoleSourceFolder;
use App\Support\Console;
use FilesystemIterator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * How many games are in a console's folder, counted off the disk rather than
 * the database — by MeasureLibrary in the background, and stored, so the pages
 * only ever read the number.
 *
 * The console cards used to count rows, which is fast and wrong: a drive
 * somebody filled over SMB says nothing to the database until a scan runs, and
 * a card claiming nothing is there is the one number a person checks against
 * what they can see in Finder.
 *
 * What it counts is files, deliberately mirroring the scanner — the same
 * exclusions, the same layout filter, the same extensions. It cannot be games:
 * a four-disc set is one game and four files, and nothing but a scan can know
 * that.
 */
final class FolderCounts
{
    /**
     * How many playable files were in this console's folder at the last count.
     *
     * Read from the database, never from the disk: no page walks a folder.
     * The count is taken by MeasureLibrary in the background — every fifteen
     * minutes, after a scan, when a console is added or changes layout, and
     * when somebody asks from the consoles page — so what arrives over the
     * share shows at the next of those. Zero before the first count.
     */
    public static function gamesIn(Console $console): int
    {
        return ConsoleSourceFolder::fileCountFor($console) ?? 0;
    }

    /** Count one console again, in the background, after something changed its folder. */
    public static function recount(Console $console): void
    {
        MeasureLibrary::dispatch($console->key);
    }

    /**
     * Walk the console's folder the way the scanner would, and count.
     *
     * Disk work: for MeasureLibrary, never for a page.
     *
     * Zero rather than an exception for a drive that is not mounted: a card
     * that renders is worth more than a page that does not, and the scan is
     * where somebody finds out the mount is gone.
     */
    public static function measure(Console $console): int
    {
        $folder = ConsoleSourceFolder::pathFor($console);

        if ($folder === null || $folder === '') {
            return 0;
        }

        $root = LibraryFolders::root().'/'.trim($folder, '/');

        if (! is_dir($root)) {
            return 0;
        }

        $layout = ConsoleSourceFolder::layoutFor($console);
        $excluded = array_map('strtolower', $console->excludeFiles);
        $found = 0;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );

            foreach ($iterator as $info) {
                if (! $info instanceof SplFileInfo || ! $info->isFile()) {
                    continue;
                }

                if (in_array(Str::lower($info->getFilename()), $excluded, true)) {
                    continue;
                }

                // Console-relative, because that is the only thing a layout
                // has an opinion about: DVD/Game.iso, not games/ps2/DVD/Game.iso.
                $relative = ltrim(Str::after($info->getPathname(), $root), '/');

                if (! $layout->accepts($relative)) {
                    continue;
                }

                if ($console->playsExtension(pathinfo($relative, PATHINFO_EXTENSION))) {
                    $found++;
                }
            }
        } catch (Throwable $e) {
            // An unreadable subtree part way through is worth saying out loud
            // and worth keeping the files already counted for.
            Log::warning('Could not count a console folder.', [
                'console' => $console->key,
                'reason' => $e->getMessage(),
            ]);
        }

        return $found;
    }
}
