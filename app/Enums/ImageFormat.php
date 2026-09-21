<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The encodings a re-encoded piece of artwork can come out as.
 *
 * Two of them, and closed: GD is what the runtime image carries, these are the
 * two formats every loader and every frontend reads, and the case value doubles
 * as the filename extension so the encoder and the filename cannot drift apart.
 */
enum ImageFormat: string
{
    case Jpeg = 'jpg';
    case Png = 'png';

    public function label(): string
    {
        return match ($this) {
            self::Jpeg => __('JPEG'),
            self::Png => __('PNG'),
        };
    }

    /** The media type, for asserting what actually came out of the encoder. */
    public function mime(): string
    {
        return match ($this) {
            self::Jpeg => 'image/jpeg',
            self::Png => 'image/png',
        };
    }
}
