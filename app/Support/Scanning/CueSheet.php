<?php

declare(strict_types=1);

namespace App\Support\Scanning;

/**
 * The track files a cuesheet names.
 *
 * A .cue is a few lines of text pointing at the data and audio tracks that
 * make up one disc. Those tracks are not games in their own right — nobody
 * would call a .bin a game — so the scanner needs to know they are spoken for
 * before it decides what to look up.
 */
final class CueSheet
{
    /**
     * Filenames named by FILE directives, in the order the sheet lists them.
     *
     * The grammar is FILE "name with spaces.bin" BINARY, but unquoted names
     * appear in the wild, so both are read. Names are returned as written:
     * resolving them against the sheet's own directory is the caller's job,
     * which is what stops a crafted path from reaching outside the library.
     *
     * @return array<int, string>
     */
    public static function tracksIn(string $contents): array
    {
        $tracks = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || stripos($line, 'FILE') !== 0) {
                continue;
            }

            // FILE "Disc 1 (Track 01).bin" BINARY
            if (preg_match('/^FILE\s+"([^"]+)"/i', $line, $m) === 1) {
                $tracks[] = $m[1];

                continue;
            }

            // FILE track01.bin BINARY
            if (preg_match('/^FILE\s+(\S+)\s+\S+\s*$/i', $line, $m) === 1) {
                $tracks[] = $m[1];
            }
        }

        return $tracks;
    }
}
