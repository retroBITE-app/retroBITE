<?php

declare(strict_types=1);

namespace App\Exceptions\RetroAchievements;

/**
 * RAHasher is not installed, not executable, or not on PATH.
 *
 * Distinct from HashFailed on purpose. This says nothing about the file, so it
 * must never set a status on the game: a missing binary is a deployment fault,
 * and marking every game HashFailed because of one would need a library-wide
 * reset to undo. The job fails loudly instead and the games stay Pending.
 */
final class HasherUnavailable extends RetroAchievementsException
{
    public function retryable(): bool
    {
        return false;
    }
}
