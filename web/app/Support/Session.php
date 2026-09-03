<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Arr;

/**
 * Typed accessors over $_SESSION, so the session key strings live in one file
 * instead of being repeated across the auth controller and middleware.
 */
final class Session
{
    private const USER_ID  = 'user_id';
    private const USERNAME = 'username';
    private const ERROR    = 'error';

    /**
     * Id of the logged-in user, or null when nobody is.
     */
    public static function userId(): ?int
    {
        $id = Arr::get($_SESSION, self::USER_ID);

        return $id === null ? null : (int) $id;
    }

    /**
     * Username of the logged-in user, or null.
     */
    public static function username(): ?string
    {
        $name = Arr::get($_SESSION, self::USERNAME);

        return is_string($name) ? $name : null;
    }

    public static function isAuthenticated(): bool
    {
        return self::userId() !== null;
    }

    /**
     * Bind the session to a user and rotate everything tied to the old identity.
     */
    public static function login(int $userId, string $username): void
    {
        session_regenerate_id(true);

        Arr::set($_SESSION, self::USER_ID, $userId);
        Arr::set($_SESSION, self::USERNAME, $username);

        Csrf::rotate();
    }

    /**
     * Discard the session entirely.
     */
    public static function destroy(): void
    {
        $_SESSION = [];
        session_unset();
        session_destroy();
    }

    /**
     * Stash a one-shot error message for the next page render.
     */
    public static function flashError(string $message): void
    {
        Arr::set($_SESSION, self::ERROR, $message);
    }

    /**
     * Read and clear the pending error message.
     */
    public static function pullError(): ?string
    {
        $error = Arr::get($_SESSION, self::ERROR);
        Arr::forget($_SESSION, self::ERROR);

        return is_string($error) ? $error : null;
    }
}
