<?php

declare(strict_types=1);

namespace App\Exceptions\RetroAchievements;

/**
 * RAHasher ran and could not hash this file.
 *
 * A truncated image, an unsupported variant, or a console id that does not fit
 * the file. About one file, so it does set a status — and it is not retryable,
 * because the same bytes will fail the same way.
 */
final class HashFailed extends RetroAchievementsException
{
    public function retryable(): bool
    {
        return false;
    }
}
