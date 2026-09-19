<?php

declare(strict_types=1);

namespace App\Exceptions\RetroAchievements;

/** 5xx, a transport failure, or a 2xx whose body would not parse. */
final class ServerError extends RetroAchievementsException
{
    public function retryable(): bool
    {
        return true;
    }

    public function retryAfter(): int
    {
        return 300;
    }
}
