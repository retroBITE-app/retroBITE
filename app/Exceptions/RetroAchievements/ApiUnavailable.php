<?php

declare(strict_types=1);

namespace App\Exceptions\RetroAchievements;

/** The API answered, but not with data — maintenance, or a gateway in the way. */
final class ApiUnavailable extends RetroAchievementsException
{
    public function retryable(): bool
    {
        return true;
    }

    public function retryAfter(): int
    {
        return 900;
    }
}
