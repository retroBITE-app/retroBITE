<?php

declare(strict_types=1);

namespace App\Support\Scanning;

use FilesystemIterator;
use Illuminate\Support\Collection;
use SplFileInfo;

/**
 * The directories a console's ROMs could be pointed at.
 *
 * Only ever what is beneath the library root — the one directory mounted into
 * the container. A console whose files live somewhere else entirely is a
 * question for GAMES_PATH, not for a text field: letting someone type a path
 * here would offer a choice the container cannot honour and hand the scanner
 * somewhere it has no business reading.
 */
final class LibraryFolders
{
    /** Deep enough for roms/playstation, shallow enough to stay quick. */
    private const DEPTH = 2;

    /**
     * Candidate folders, relative to the library root, shallowest first.
     *
     * @return Collection<int, string>
     */
    public static function all(): Collection
    {
        $root = self::root();

        if (! is_dir($root)) {
            return collect();
        }

        return collect(self::walk($root, $root, self::DEPTH))->sort()->values();
    }

    /**
     * Folders not already claimed by a console, by convention or by choice.
     *
     * @param  array<int, string>  $taken
     * @return Collection<int, string>
     */
    public static function available(array $taken): Collection
    {
        return self::all()->reject(fn (string $folder) => in_array($folder, $taken, true))->values();
    }

    public static function root(): string
    {
        return rtrim((string) config('settings.games_path'), '/');
    }

    /** Whether a chosen folder is really inside the library root. */
    public static function contains(string $relative): bool
    {
        $relative = trim($relative, '/');

        if ($relative === '' || str_contains($relative, '..')) {
            return false;
        }

        return is_dir(self::root().'/'.$relative);
    }

    /**
     * @return array<int, string>
     */
    private static function walk(string $root, string $directory, int $depth): array
    {
        if ($depth <= 0) {
            return [];
        }

        $found = [];

        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (! $entry instanceof SplFileInfo || ! $entry->isDir()) {
                continue;
            }

            $relative = ltrim(str_replace($root, '', $entry->getPathname()), '/');
            $found[] = $relative;
            $found = [...$found, ...self::walk($root, $entry->getPathname(), $depth - 1)];
        }

        return $found;
    }
}
