<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What colour a rating is shown in.
 *
 * Four bands off the existing palette rather than a traffic light. The design
 * has no success colour on purpose — app.css says so where it defines the
 * feedback hues — and the accent is what it uses for good news everywhere
 * else, the achievement bars included. A green here would be the only one in
 * the interface.
 *
 * So the scale descends through the palette instead of across it: bright gold,
 * drab gold, orange, rose. Read as a ramp rather than as a verdict, which
 * suits a mark nobody agrees the meaning of anyway.
 *
 * Returned as CSS variables and set as inline styles, not as Tailwind classes:
 * a class assembled at runtime is a class Tailwind never saw in the source and
 * therefore never generated.
 *
 * @see CoverGeometry for the same shape of reader.
 */
final class RatingBand
{
    /**
     * The lowest rating in each band, highest first, against its colour.
     *
     * Boundaries are the round numbers somebody filtering the shelf would
     * pick, so the colour changes where the filter does.
     */
    private const BANDS = [
        80 => '--color-accent',
        60 => '--color-accent-muted',
        40 => '--color-warn',
        0 => '--color-danger',
    ];

    /** The band colour for a rating, as a CSS variable reference. */
    public static function color(int $rating): string
    {
        foreach (self::BANDS as $floor => $color) {
            if ($rating >= $floor) {
                return "var({$color})";
            }
        }

        // Unreachable while a band starts at zero, and cheaper than trusting
        // that it always will.
        return 'var(--color-danger)';
    }

    /**
     * Text that stays legible on any of the four.
     *
     * One answer rather than four: every band colour is a light one, which is
     * what makes the near-black the palette already uses for text on the
     * accent right for all of them.
     */
    public static function ink(): string
    {
        return 'var(--color-accent-foreground)';
    }
}
