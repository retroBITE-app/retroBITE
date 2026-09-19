<?php

declare(strict_types=1);

namespace App\Exceptions\RetroAchievements;

/** HTTP 400 — we asked something malformed. Our bug, not theirs. */
final class InvalidRequest extends RetroAchievementsException
{
    public function retryable(): bool
    {
        return false;
    }
}
