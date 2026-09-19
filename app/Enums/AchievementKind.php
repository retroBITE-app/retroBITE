<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What an achievement is for, in RetroAchievements' own terms.
 *
 * Most achievements have none of these: the field marks out the few that say
 * something about finishing the game, or that can be permanently lost.
 */
enum AchievementKind: string
{
    /** One of the steps that make up beating the game. */
    case Progression = 'progression';

    /** Beating the game. */
    case WinCondition = 'win_condition';

    /** Can be missed for good, so worth flagging before somebody walks past it. */
    case Missable = 'missable';

    public function label(): string
    {
        return match ($this) {
            self::Progression => 'Progression',
            self::WinCondition => 'Win condition',
            self::Missable => 'Missable',
        };
    }
}
