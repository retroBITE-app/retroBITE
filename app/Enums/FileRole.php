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

    public function label(): string
    {
        return match ($this) {
            self::Rom => 'Game',
            self::Sheet => 'Cuesheet',
            self::Track => 'Track',
            self::Playlist => 'Disc playlist',
        };
    }

    /**
     * Whether this file is worth spending a provider lookup on.
     *
     * A track counts, and it is often the only thing that does: in a cue/bin
     * set the .bin holds the data, and it is what ScreenScraper indexes with a
     * correct size and checksum. Sheets and playlists do not. Both are in the
     * provider's database, but their entries are contributed and unreliable —
     * one .m3u is on record carrying the size of the entire four-disc set —
     * so a lookup on either is likely to miss and burn the failed-lookup
     * quota, which is ten times scarcer than the ordinary one.
     */
    public function identifiable(): bool
    {
        return $this === self::Rom || $this === self::Track;
    }
}
