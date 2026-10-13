<?php

declare(strict_types=1);

namespace App\Support\Scanning;

use Illuminate\Support\Str;

/**
 * Which disc of a set a file is, going by its name.
 *
 * For the files of a game that no playlist numbers: "(Disc 2)" or "Disc 2" in
 * the name where there is one, and natural order by name where there is not,
 * so ten comes after three rather than after one. Shared by the playlist
 * writer, the conversions and the transfers, which all have to put a set's
 * discs in order, and by the scan, which finds a set by its name ({@see set()}).
 */
final class DiscOrder
{
    private const PATTERN = '/\bdisc\s*(\d+)/i';

    /** What a disc number looks like with the brackets round it, for taking it out of a name. */
    private const BRACKETED = '/\s*[\(\[]?\bdisc\s*\d+[\)\]]?/i';

    /**
     * The part of a name that makes a file one disc of a set: in brackets,
     * so "Amiga CD32" is not disc 32 of anything.
     */
    private const SET = '/\s*\((?:disc|disk|cd)\s*(\d+)(?:\s*of\s*\d+)?\)/i';

    /** The disc a name says it is, or PHP_INT_MAX when it says none. Two matches at most: it runs inside a sort. */
    public static function number(string $name): int
    {
        $number = Str::match(self::SET, $name);
        $number = $number !== '' ? $number : Str::match(self::PATTERN, $name);

        return $number !== '' ? (int) $number : PHP_INT_MAX;
    }

    /** A name without its disc number: "MGS (Disc 1)" is "MGS". The name as it was when that leaves nothing. */
    public static function strip(string $name): string
    {
        $stripped = trim(Str::replaceMatches(self::BRACKETED, '', $name));

        return $stripped !== '' ? $stripped : $name;
    }

    /**
     * The name a disc shares with the other discs of its set: "FF7 (Disc 2)"
     * is "FF7", and so is "FF7 (CD 2 of 3)". Null for a name that does not
     * say it is one disc of several.
     */
    public static function set(string $name): ?string
    {
        $set = preg_replace(self::SET, '', $name);

        return $set !== null && $set !== $name ? $set : null;
    }

    /** Whether the name says which disc it is. */
    public static function numbered(string $name): bool
    {
        return Str::isMatch(self::PATTERN, $name);
    }

    /** For a sort(): by disc number, then naturally by name. */
    public static function compare(string $a, string $b): int
    {
        return (self::number($a) <=> self::number($b)) ?: strnatcasecmp($a, $b);
    }
}
