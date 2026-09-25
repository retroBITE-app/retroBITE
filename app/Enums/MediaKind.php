<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Support\Collection;

/**
 * The artwork slots retroBITE caches for a game.
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

    /** The disc's own label scan — what OPL draws as a game's icon. */
    case Disc = 'disc';

    /**
     * The slot a provider media type fills, or null when it fills none.
     *
     * The provider sends far more types than these three have room for — videos,
     * manuals, marquees — so null is an ordinary answer, and the caller falls
     * back to showing the raw type it was given.
     */
    public static function fromScreenScraperType(string $type): ?self
    {
        return Collection::make(self::cases())
            ->first(fn (self $kind) => in_array($type, $kind->screenScraperTypes(), true));
    }

    /**
     * Human-readable name for the UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::Cover => 'Cover',
            self::Logo => 'Logo',
            self::Backdrop => 'Backdrop',
            self::Disc => 'Disc',
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
     * The types that are painted key art rather than a frame grabbed from play.
     *
     * Only a backdrop has both kinds: the provider files in-game screenshots
     * beside real wallpaper, and a screenshot blown up full-bleed reads as a
     * mistake. Every other slot returns its whole list, since a cover or a logo
     * is never a screenshot.
     *
     * @return string[]
     */
    public function keyArtTypes(): array
    {
        return match ($this) {
            self::Backdrop => ['fanart', 'background'],
            default => $this->screenScraperTypes(),
        };
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
            self::Disc => ['support-2D'],
        };
    }
}
