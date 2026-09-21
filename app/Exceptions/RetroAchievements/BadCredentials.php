<?php

declare(strict_types=1);

namespace App\Exceptions\RetroAchievements;

/**
 * HTTP 401 — the API key is wrong, missing, or belongs to nobody.
 *
 * Not retryable: the key will still be wrong in five minutes, and every retry
 * is a request spent proving it.
 */
final class BadCredentials extends RetroAchievementsException
{
    public function retryable(): bool
    {
        return false;
    }
}
