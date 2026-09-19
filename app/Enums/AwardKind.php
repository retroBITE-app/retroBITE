<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * RetroAchievements' verdict on how far somebody got in a game.
 *
 * Four cases and not three: beaten is recorded separately for softcore and
 * hardcore, and collapsing them would throw away exactly the distinction the
 * rest of this is built to keep. Always taken from the provider, never worked
 * out from our own unlock rows.
 */
enum AwardKind: string
{
    case BeatenSoftcore = 'beaten-softcore';
    case BeatenHardcore = 'beaten-hardcore';

    /** Every achievement, softcore. */
    case Completed = 'completed';

    /** Every achievement, hardcore. */
    case Mastered = 'mastered';

    public function label(): string
    {
        return match ($this) {
            self::BeatenSoftcore => 'Beaten',
            self::BeatenHardcore => 'Beaten (hardcore)',
            self::Completed => 'Completed',
            self::Mastered => 'Mastered',
        };
    }

    public function hardcore(): bool
    {
        return $this === self::BeatenHardcore || $this === self::Mastered;
    }
}
