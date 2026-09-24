<?php

namespace App\Enums;

/**
 * The two sizes a cover is kept at for the interface, by where it is shown.
 *
 * Each is a square box the image is scaled down to fit — whichever side meets
 * it first — keeping its shape and never enlarged. The box is about twice the
 * largest size the view draws it at, so it stays sharp on a high-density
 * screen: the list view draws a cover at 48 CSS pixels, the shelf at up to 280.
 */
enum ThumbnailSize: string
{
    case List = 'list';
    case Grid = 'grid';

    /**
     * The side of the square the image is scaled to fit.
     *
     * @return int<1, max>
     */
    public function box(): int
    {
        return match ($this) {
            self::List => 128,
            self::Grid => 560,
        };
    }

    /** The media column holding this size's path. */
    public function column(): string
    {
        return 'thumbnail_'.$this->value.'_path';
    }
}
