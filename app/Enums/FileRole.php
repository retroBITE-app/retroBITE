<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What part a file plays in the game it belongs to.
 *
 * A game is often more than one file, and they are not interchangeable. A
 * PlayStation title can be a playlist pointing at four cuesheets, each naming
 * a data track — nine files, one game, and only one of them worth asking the
 * provider about.
 */
enum FileRole: string
{
    /** A complete game image: iso, chd, sfc, nes. Identifiable on its own. */
    case Rom = 'rom';

    /** A cuesheet or similar index that names other files. */
    case Sheet = 'sheet';

    /** A data or audio track named by a sheet. Not identifiable on its own. */
    case Track = 'track';

    /** An m3u listing the discs of a multi-disc game. */
    case Playlist = 'playlist';

    /** A system BIOS sitting in the console's folder. Belongs to no game. */
    case Bios = 'bios';

    public function label(): string
    {
        return match ($this) {
            self::Rom => 'Game',
            self::Sheet => 'Cuesheet',
            self::Track => 'Track',
            self::Playlist => 'Disc playlist',
            self::Bios => 'BIOS',
        };
    }

    /**
     * Whether this file is worth spending a provider lookup on.
     *
     * Tracks are not: they are named by a sheet that already identifies the
     * game. Playlists and sheets are indexed by ScreenScraper but their entries
     * are unreliable — a .m3u is on record with the size of the whole disc set
     * — so a lookup on one is likely to miss and burn the scarce failed quota.
     */
    public function identifiable(): bool
    {
        return $this === self::Rom;
    }
}
