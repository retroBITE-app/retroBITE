<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\LoginAttempt;

class LoginAttemptRepository
{
    /**
     * The counter for one address, or null when it has never failed.
     */
    public function find(string $ip): ?LoginAttempt
    {
        return LoginAttempt::find($ip);
    }

    /**
     * Replace the counter for one address.
     */
    public function put(string $ip, int $attempts, int $windowStart, ?int $lockedUntil): void
    {
        LoginAttempt::upsert(
            [[
                'ip'           => $ip,
                'attempts'     => $attempts,
                'window_start' => $windowStart,
                'locked_until' => $lockedUntil,
            ]],
            uniqueBy: ['ip'],
            update:   ['attempts', 'window_start', 'locked_until'],
        );
    }

    /**
     * Forget one address entirely.
     */
    public function delete(string $ip): void
    {
        LoginAttempt::where('ip', $ip)->delete();
    }
}
