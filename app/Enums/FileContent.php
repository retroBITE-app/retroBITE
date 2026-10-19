<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Scanning\DumpName;
use Illuminate\Support\Str;

/**
 * What a file holds: a game, or content for one — downloadable content, an
 * update. Apart from FileRole, which says how a file is built (an image, a
 * cuesheet, a track) rather than what is in it.
 *
 * Read from the dump's name by the scanner, for any console, and off the disc
 * by a console's toolbox where it can tell (a PS3 add-on disc); the disc wins.
 * Null on a file is unknown, never assumed to be a game.
 */
enum FileContent: string
{
    case Game = 'game';

    /** Extra content for a game: an add-on disc, a DLC package. */
    case Dlc = 'dlc';

    /** A patch for a game. */
    case Update = 'update';

    /** Dump-name tags, lower case, by the content their start says. */
    private const TAGS = [
        'add-on' => self::Dlc,
        'dlc' => self::Dlc,
        'update' => self::Update,
        'patch' => self::Update,
    ];

    /**
     * What a dump's name says it holds — "(Add-On Disc)", "(DLC)", "(Update)",
     * "(Patch)" — or null when no tag says, which is most names: a game's name
     * carries no tag for being one, so the fallback is unknown, not Game.
     */
    public static function fromName(string $filename): ?self
    {
        foreach (DumpName::roundTags($filename) as $tag) {
            $lower = Str::lower($tag);

            foreach (self::TAGS as $start => $content) {
                if (Str::startsWith($lower, $start)) {
                    return $content;
                }
            }
        }

        return null;
    }

    /** Whether this is content for a game rather than one: what a shelf marks. */
    public function extra(): bool
    {
        return $this !== self::Game;
    }

    /** The badge's text. */
    public function label(): string
    {
        return match ($this) {
            self::Game => __('Game'),
            self::Dlc => __('DLC'),
            self::Update => __('Update'),
        };
    }

    /** One line on what it means for playing. */
    public function hint(): string
    {
        return match ($this) {
            self::Game => __('A game.'),
            self::Dlc => __('Extra content for the base game; does not play on its own.'),
            self::Update => __('A patch for the base game; does not play on its own.'),
        };
    }

    /** The badge's icon, from Flux's set. */
    public function icon(): string
    {
        return match ($this) {
            self::Game => 'play',
            self::Dlc => 'puzzle-piece',
            self::Update => 'arrow-path-rounded-square',
        };
    }
}
