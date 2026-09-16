<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The three artwork slots retroBITE caches for a game.
 *
 * Owns the mapping to both sides of the boundary: the `*_url` keys used in the
 * metadata payload and the ScreenScraper media-type names, each of which was
 * previously spelled out again in every consumer.
 */
enum MediaKind: string
{
    case Cover = 'cover';
    case Logo = 'logo';
    case Backdrop = 'backdrop';

    /**
     * Human-readable name for the UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::Cover => 'Cover',
            self::Logo => 'Logo',
            self::Backdrop => 'Backdrop',
        };
    }

    /**
     * Key this slot occupies in a metadata payload, e.g. `cover_url`.
     */
    public function payloadKey(): string
    {
        return $this->value.'_url';
    }

    /**
     * ScreenScraper media-type names for this slot, most preferred first.
     *
     * @return string[]
     */
    public function screenScraperTypes(): array
    {
        return match ($this) {
            self::Cover => ['box-2D', 'box-3D'],
            self::Logo => ['wheel', 'wheel-hd', 'wheel-carbon', 'wheel-steel'],
            self::Backdrop => ['fanart', 'background', 'sstitle', 'ss', 'screenmarquee'],
        };
    }
}
