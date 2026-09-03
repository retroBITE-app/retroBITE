<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Arr;

/**
 * Reads environment variables through every channel PHP might expose them on.
 *
 * `$_ENV` alone is not enough: it is only populated when `variables_order`
 * includes `E`, which the distro php.ini commonly omits. Relying on it made
 * every env-backed setting — the login credentials included — silently fall
 * back to its built-in default.
 */
final class Env
{
    /**
     * The value of an environment variable, or $default when it is unset or blank.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = Arr::get($_ENV, $key) ?? Arr::get($_SERVER, $key) ?? self::fromGetenv($key);

        return $value === null || $value === '' ? $default : $value;
    }

    /**
     * getenv() reports a missing variable as false; normalise that to null.
     */
    private static function fromGetenv(string $key): ?string
    {
        $value = getenv($key);

        return $value === false ? null : $value;
    }
}
