<?php

declare(strict_types=1);

namespace App\Support\Layouts;

/**
 * ROMs loose in the console's folder, with RetroArch's thumbnail cache beside
 * them.
 *
 * The structure is barely a structure — what makes it worth declaring is the
 * media directories. RetroArch writes a PNG per game into Named_Boxarts and its
 * siblings, and scanning those turns one game into four.
 */
final class RetroArchLayout extends ConsoleLayout
{
    public function key(): string
    {
        return 'retroarch';
    }

    public function label(): string
    {
        return __('RetroArch');
    }

    public function description(): string
    {
        return __('Games loose in the folder, with thumbnails cached in media directories beside them.');
    }

    /** @return string[] */
    public function gameDirectories(): array
    {
        return [''];
    }

    /** @return string[] */
    public function ignoredDirectories(): array
    {
        return ['media', 'thumbnails', 'Named_Boxarts', 'Named_Snaps', 'Named_Titles'];
    }

    /**
     * The thumbnail tree, beside the games.
     *
     * The games themselves need nothing made: they sit loose in the console's
     * own folder, which is already there by the time anybody picks a layout.
     * These three are for the artwork — RetroArch names a thumbnail after the
     * game and files it by kind, and a library organised this way wants the
     * folders whether or not anything has filled them yet.
     *
     * Worth being plain about the limit: RetroArch reads its own thumbnail
     * directory by default, not this one, so somebody has to point it here.
     * The folders are the convention, not a guarantee the emulator will look.
     *
     * @return string[]
     */
    public function scaffold(): array
    {
        return ['media/Named_Boxarts', 'media/Named_Snaps', 'media/Named_Titles'];
    }
}
