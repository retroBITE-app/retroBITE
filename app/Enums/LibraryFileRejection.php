<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why moving a game or deleting one of its files was refused.
 *
 * Owns the sentence the person reads, so the page never passes a path or an
 * exception's text back to the browser.
 */
enum LibraryFileRejection: string
{
    case Unconfigured = 'unconfigured';
    case Destination = 'destination';
    case SameFolder = 'same_folder';
    case Missing = 'missing';
    case OutsideConsole = 'outside_console';
    case Exists = 'exists';
    case Unwritable = 'unwritable';
    case NotThisGame = 'not_this_game';

    /**
     * The fixed sentence shown for this refusal.
     */
    public function label(): string
    {
        return match ($this) {
            self::Unconfigured => __('This console is not in the library, so its files cannot be moved.'),
            self::Destination => __('That folder is not one this console\'s layout reads games from.'),
            self::SameFolder => __('The game is already in that folder.'),
            self::Missing => __('Some of this game\'s files are missing from disk, so it cannot be moved.'),
            self::OutsideConsole => __('Some of this game\'s files are outside the console\'s folder.'),
            self::Exists => __('A file with the same name is already in that folder. Nothing was moved.'),
            self::Unwritable => __('The library could not be changed. Nothing was moved or deleted.'),
            self::NotThisGame => __('That file does not belong to this game.'),
        };
    }
}
