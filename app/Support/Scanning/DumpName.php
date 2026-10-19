<?php

declare(strict_types=1);

namespace App\Support\Scanning;

/**
 * The tags No-Intro, Redump and GoodTools write into a dump's file name:
 * "Aerostar (USA, Europe) (Rev 1) [!].zip" carries round tags for what the dump
 * is — region, revision, add-on disc — and square ones for GoodTools' flags.
 * Read here once for everything that reads a name's tags.
 */
final class DumpName
{
    /**
     * What each round-bracket tag says, trimmed, in the order written; none
     * for a name without one. The extension is not part of the name.
     *
     * @return list<string>
     */
    public static function roundTags(string $filename): array
    {
        return self::tags('/\(([^)]*)\)/', $filename);
    }

    /**
     * What each square-bracket tag says, trimmed, in the order written.
     *
     * @return list<string>
     */
    public static function squareTags(string $filename): array
    {
        return self::tags('/\[([^\]]*)\]/', $filename);
    }

    /**
     * The first group of every match of $pattern in the name, trimmed.
     *
     * @return list<string>
     */
    private static function tags(string $pattern, string $filename): array
    {
        preg_match_all($pattern, pathinfo($filename, PATHINFO_FILENAME), $matches);

        return array_map('trim', $matches[1]);
    }
}
