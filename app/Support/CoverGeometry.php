<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Arr;

/**
 * What shape a console's covers are.
 *
 * A SNES box is wide and flat where a PS2 case is tall, and the shape of both
 * lives in config/consoles/*.php as `cover_aspect`. This is the one place that
 * reads it, since the game page and the library cards have to agree.
 *
 * The shelf is fluid: a card fills its column, the height follows from
 * {@see aspect()}, and {@see orientation()} decides how many columns a row
 * has. The game page is not a grid, and levels its covers at
 * {@see HERO_HEIGHT} instead.
 */
final class CoverGeometry
{
    public const DEFAULT_ASPECT = '5/7';

    /** How tall the game page's cover stands, whatever the console. */
    public const HERO_HEIGHT = 280;

    /**
     * How wide a cover stands on the game page, at {@see HERO_HEIGHT}.
     *
     * For a placeholder, which has no art to take a width from.
     */
    public static function heroWidth(?Console $console): int
    {
        return (int) round(self::HERO_HEIGHT * self::ratioOf($console));
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

    /** Width over height: under one stands tall, over one lies wide. */
    public static function ratioOf(?Console $console): float
    {
        return self::ratio(self::aspect($console)) ?? 5 / 7;
    }

    /**
     * Whether the covers stand, lie, or are near enough square — which is
     * how many to a shelf row. A tenth either side of square counts as it:
     * a jewel case is a touch off, and is still one.
     *
     * @return 'portrait'|'square'|'landscape'
     */
    public static function orientation(?Console $console): string
    {
        $ratio = self::ratioOf($console);

        return match (true) {
            $ratio < 0.9 => 'portrait',
            $ratio > 1.1 => 'landscape',
            default => 'square',
        };
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
