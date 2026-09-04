<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\LoginAttemptRepository;

/**
 * Rate-limits failed logins per client address. retroBITE ships one known account,
 * so an unthrottled form is an unlimited password guess against a single target.
 */
class LoginThrottleService
{
    /** Failures tolerated inside one window before locking out. */
    private const MAX_ATTEMPTS = 5;

    /** Seconds a run of failures is counted over. */
    private const WINDOW_SECONDS = 900;

    /** Seconds an address stays locked once the limit is crossed. */
    private const LOCKOUT_SECONDS = 900;

    public function __construct(
        private LoginAttemptRepository $attempts,
    ) {}

    /**
     * Is this address currently locked out?
     */
    public function isLocked(string $ip): bool
    {
        return $this->retryAfter($ip) > 0;
    }

    /**
     * Seconds until this address may try again; 0 when it is not locked.
     */
    public function retryAfter(string $ip): int
    {
        $lockedUntil = $this->attempts->find($ip)?->locked_until;

        if ($lockedUntil === null) {
            return 0;
        }

        return max(0, $lockedUntil - time());
    }

    /**
     * Count one failed attempt, locking the address out if it crosses the limit.
     */
    public function recordFailure(string $ip): void
    {
        $now    = time();
        $record = $this->attempts->find($ip);

        // A run that started outside the window is stale — begin counting again.
        $isStale = $record === null || ($now - $record->window_start) > self::WINDOW_SECONDS;

        $attempts    = $isStale ? 1 : $record->attempts + 1;
        $windowStart = $isStale ? $now : $record->window_start;

        $this->attempts->put(
            $ip,
            $attempts,
            $windowStart,
            $attempts >= self::MAX_ATTEMPTS ? $now + self::LOCKOUT_SECONDS : null,
        );
    }

    /**
     * Forget an address's failures after a successful login.
     */
    public function clear(string $ip): void
    {
        $this->attempts->delete($ip);
    }
}
