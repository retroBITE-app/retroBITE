<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What kind of image some bytes are, read off their first few bytes.
 *
 * One answer for the whole application: artwork from the provider and images
 * added to a document are both named for what they are, not for what they
 * arrived called.
 */
final class ImageType
{
    /** The extension the bytes are, or null for anything that is not one of these images. */
    public static function extensionOf(string $contents): ?string
    {
        return match (true) {
            str_starts_with($contents, "\x89PNG") => 'png',
            str_starts_with($contents, "\xFF\xD8\xFF") => 'jpg',
            str_starts_with($contents, 'GIF8') => 'gif',
            str_starts_with($contents, 'RIFF') && substr($contents, 8, 4) === 'WEBP' => 'webp',
            default => null,
        };
    }
}
