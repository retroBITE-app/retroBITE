<?php

declare(strict_types=1);

namespace App\Support\Scanning;

/**
 * The discs an .m3u playlist names.
 *
 * One line per disc, in order, with # for comments. Emulators use these to
 * hold a multi-disc game together, which makes the playlist the one place on
 * disk that says "these four files are one game" — the scanner reads it first
 * for exactly that reason.
 */
final class Playlist
{
    /**
     * Entries in listed order, comments and blank lines dropped.
     *
     * Returned as written. Resolving them against the playlist's directory is
     * the caller's job.
     *
     * @return array<int, string>
     */
    public static function entriesIn(string $contents): array
    {
        $entries = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);

            // #EXTM3U and #EXTINF carry no path.
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $entries[] = $line;
        }

        return $entries;
    }
}
