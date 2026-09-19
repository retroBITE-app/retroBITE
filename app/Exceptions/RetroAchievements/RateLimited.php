<?php

declare(strict_types=1);

namespace App\Exceptions\RetroAchievements;

/**
 * HTTP 429 — too many requests.
 *
 * RetroAchievements rate-limits but publishes no numbers, so the wait is a
 * guess on the generous side rather than something read off a header.
 */
final class RateLimited extends RetroAchievementsException
{
    public function retryable(): bool
    {
        return true;
    }

    public function retryAfter(): int
    {
        return 120;
    }
}
