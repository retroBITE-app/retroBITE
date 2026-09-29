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
 * writer and the conversions, which both have to put a set's discs in order.
 */
final class DiscOrder
{
    private const PATTERN = '/\bdisc\s*(\d+)/i';

    /** What a disc number looks like with the brackets round it, for taking it out of a name. */
    private const BRACKETED = '/\s*[\(\[]?\bdisc\s*\d+[\)\]]?/i';

    /** The disc a name says it is, or PHP_INT_MAX when it says none. One match: it runs inside a sort. */
    public static function number(string $name): int
    {
        $number = Str::match(self::PATTERN, $name);

        return $number !== '' ? (int) $number : PHP_INT_MAX;
    }

    /** A name without its disc number: "MGS (Disc 1)" is "MGS". The name as it was when that leaves nothing. */
    public static function strip(string $name): string
    {
        $stripped = trim(Str::replaceMatches(self::BRACKETED, '', $name));

        return $stripped !== '' ? $stripped : $name;
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
