<?php

declare(strict_types=1);

namespace App\Exceptions\RetroAchievements;

use RuntimeException;

/**
 * Base for every RetroAchievements failure that is not simply "no set".
 *
 * Shaped like App\Exceptions\ScreenScraper for the same reason it exists
 * there: a game with no achievements and a request that never got asked are
 * different answers, and reading the second as the first marks a library empty
 * in silence.
 */
abstract class RetroAchievementsException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly string $body = '',
    ) {
        parent::__construct($message);
    }

    /** Whether waiting and asking the same thing again could succeed. */
    abstract public function retryable(): bool;

    /** Seconds to wait before the next attempt, or null to let the caller decide. */
    public function retryAfter(): ?int
    {
        return null;
    }
}
