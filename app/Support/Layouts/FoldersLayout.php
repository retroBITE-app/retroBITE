<?php

declare(strict_types=1);

namespace App\Support\Layouts;

use Illuminate\Support\Str;

/**
 * One folder per game, directly in the console's folder.
 *
 * What optical drive emulators and most CD-system frontends expect: the
 * console's folder is the card or the share, and each game is a folder in it
 * holding a cue with its bins, or a playlist and its discs. Not one console's
 * convention, so not named for one — it is offered to the CD systems that use
 * it, and PS1 is only the first of them.
 *
 * The folder is the game. That is why a title comes from the folder rather
 * than the file: "FF9 (Disc 2).cue" and "FF9 (Disc 3).cue" are two files of
 * one game, and named for their folder they land on one game without a
 * playlist having to say so.
 */
final class FoldersLayout extends ConsoleLayout
{
    public function key(): string
    {
        return 'folders';
    }

    public function label(): string
    {
        return __('Game folders');
    }

    public function description(): string
    {
        return __('One folder per game, directly in the console\'s folder: a cue with its bins, or a playlist and its discs.');
    }

    /** @return string[] */
    public function gameDirectories(): array
    {
        return [''];
    }

    /** @return string[] */
    public function ignoredDirectories(): array
    {
        return [];
    }

    /** Games live one to a folder, and the uploader and the organizer make those folders. */
    public function perGameFolders(): bool
    {
        return true;
    }

    /**
     * The game's folder, for a file inside one; the file's own name for one
     * loose at the top, which is a game somebody has not filed yet.
     */
    public function titleFor(string $relative): string
    {
        $relative = trim($relative, '/');

        return Str::contains($relative, '/')
            ? Str::before($relative, '/')
            : parent::titleFor($relative);
    }
}
