<?php

declare(strict_types=1);

namespace App\Support\Scanning;

use App\Models\ConsoleSourceFolder;
use App\Support\Console;
use FilesystemIterator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * How many games are in a console's folder, asked of the disk rather than the
 * database.
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
    /** How long one console's count is good for. Short: an upload moves it. */
    private const TTL = 60;

    /** How long a stale count may still be served while a fresh one is taken. */
    private const STALE = 300;

    /**
     * How many playable files are in this console's folder right now.
     *
     * Cache::flexible() rather than remember(): a remember() that has just
     * expired makes one unlucky visitor pay for walking five thousand files
     * inside their own request, and the page polls itself every two seconds.
     * This hands back the stale count immediately and refreshes after the
     * response, under a lock, so the poll cannot stampede a spinning disk.
     */
    public static function gamesIn(Console $console): int
    {
        return Cache::flexible(
            self::key($console),
            [self::TTL, self::STALE],
            function () use ($console): int {
                return self::count($console);
            },
        );
    }

    /**
     * Forget one console's count, after anything that changes the answer.
     *
     * Called explicitly rather than from a model event: setLayout() is a mass
     * update, which fires no events, so half the invalidation would silently
     * not happen.
     */
    public static function forget(Console $console): void
    {
        Cache::forget(self::key($console));
    }

    private static function key(Console $console): string
    {
        return 'library.count.'.$console->key;
    }

    /**
     * Walk the console's folder the way the scanner would, and count.
     *
     * Zero rather than an exception for a drive that is not mounted: a card
     * that renders is worth more than a page that does not, and the scan is
     * where somebody finds out the mount is gone.
     */
    private static function count(Console $console): int
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
