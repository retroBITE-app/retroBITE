<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Arr;

/**
 * How tall and how wide a console's covers stand.
 *
 * A SNES box is wide and flat where a PS2 case is tall, so a shelf of covers is
 * levelled by height and each console keeps its own proportions. The numbers
 * live in config/consoles/*.php; this is the one place that reads them, since
 * the game page and the library cards have to agree.
 */
final class CoverGeometry
{
    /** What a console without measurements of its own falls back to. */
    public const DEFAULT_HEIGHT = 280;

    public const DEFAULT_ASPECT = '5/7';

    /** How tall this console's covers stand, in pixels. */
    public static function height(?Console $console): int
    {
        $height = (int) Arr::get($console?->toMetaArray() ?? [], 'cover_height');

        return $height > 0 ? $height : self::DEFAULT_HEIGHT;
    }

    /**
     * The cover proportions, as a CSS aspect-ratio value such as "5/7".
     *
     * Falls back on an empty or malformed entry rather than only on a missing
     * key: Arr::get's default covers the second case alone, and an aspect of ''
     * would collapse the box.
     */
    public static function aspect(?Console $console): string
    {
        $aspect = (string) Arr::get($console?->toMetaArray() ?? [], 'cover_aspect');

        return self::ratio($aspect) !== null ? $aspect : self::DEFAULT_ASPECT;
    }

    /**
     * How wide a cover stands at that height, in pixels.
     *
     * A ratio alone is not enough for either caller: a shelf card is a
     * shrink-to-fit flex item, so without a width in pixels it takes the one
     * its own art happens to have, and a placeholder has no art to take a
     * width from at all.
     */
    public static function width(?Console $console): int
    {
        $ratio = self::ratio(self::aspect($console)) ?? 5 / 7;

        return (int) round(self::height($console) * $ratio);
    }

    /** The ratio an "N/M" string describes, or null when it describes none. */
    private static function ratio(string $aspect): ?float
    {
        [$width, $depth] = array_pad(explode('/', $aspect), 2, null);

        if ((float) $width <= 0 || (float) $depth <= 0) {
            return null;
        }

        return (float) $width / (float) $depth;
    }
}
