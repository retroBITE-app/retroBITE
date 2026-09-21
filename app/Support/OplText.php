<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The shape Open PS2 Loader reads words in.
 *
 * The sibling of CoverArt: that one puts a picture into the form OPL can draw,
 * this one does the same for a line of text. OPL renders a single line of plain
 * ASCII in a bitmap font with no glyph for anything else, and shows a fixed
 * number of characters of a synopsis before it stops.
 *
 * Pure, and carries its one limit in the constructor, so the rules can be
 * tested without a disc, a database or a container.
 */
final class OplText
{
    /**
     * @param  int  $maximum  characters of a synopsis OPL will show — kept at
     *                        the previous build's figure so regenerating a
     *                        drive does not rewrite every file it already has
     *                        for the sake of a different cut
     */
    public function __construct(public readonly int $maximum = 300) {}

    /**
     * One line of plain ASCII, because that is all OPL can draw.
     */
    public function oneLine(string $value): string
    {
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return trim(Str::ascii($value));
    }

    /**
     * A synopsis cut to what OPL will show, on a word boundary.
     *
     * The ellipsis is three ASCII dots rather than one character, which is what
     * the files on a working drive carry — OPL's font has no glyph for the
     * other one.
     */
    public function summarise(string $text): string
    {
        $text = $this->oneLine($text);

        if (mb_strlen($text) <= $this->maximum) {
            return $text;
        }

        $cut = mb_substr($text, 0, $this->maximum);
        $lastSpace = mb_strrpos($cut, ' ');

        if ($lastSpace !== false && $lastSpace > 0) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, " \t.,;:!?-").'...';
    }
}
