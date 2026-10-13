<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a file row was archived rather than kept live (docs/adr/0009).
 *
 * The file is gone from the library in every case; the row stays as the
 * record of which dump it was.
 */
enum FileArchiveReason: string
{
    /** Converted into another format, the source not kept. */
    case Converted = 'converted';

    /** Replaced in place by its decrypted image. */
    case Decrypted = 'decrypted';

    /** Deleted by hand on the game page. */
    case Deleted = 'deleted';

    /** Missing for longer than the library keeps a missing file live. */
    case Pruned = 'pruned';

    public function label(): string
    {
        return match ($this) {
            self::Converted => __('Converted'),
            self::Decrypted => __('Decrypted'),
            self::Deleted => __('Deleted'),
            self::Pruned => __('Pruned'),
        };
    }
}
