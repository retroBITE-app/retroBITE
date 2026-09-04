<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Per-session CSRF token — sole owner of its storage, transport names and comparison.
 * Mirrored into a JS-readable `XSRF-TOKEN` cookie because axios (and so Inertia)
 * echoes that back as `X-XSRF-TOKEN` automatically; raw fetches send `X-CSRF-Token`.
 */
final class Csrf
{
    /** Session key holding the raw token. */
    public const SESSION_KEY = '_csrf_token';

    /** Cookie the frontend reads the token from. Deliberately not HttpOnly. */
    public const COOKIE_NAME = 'XSRF-TOKEN';

    /** Body field accepted as a fallback when neither header is present. */
    public const FIELD_NAME = '_token';

    /** Headers the token may arrive in, in precedence order. */
    private const HEADERS = ['X-XSRF-TOKEN', 'X-CSRF-Token'];

    private const BYTES = 32;

    /**
     * The current session's token, minting one on first use.
     */
    public static function token(): string
    {
        $token = Arr::get($_SESSION, self::SESSION_KEY);

        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(self::BYTES));
            Arr::set($_SESSION, self::SESSION_KEY, $token);
        }

        return $token;
    }

    /**
     * Mint a fresh token, discarding the current one. Call on any privilege change
     * so a token captured beforehand stops working.
     */
    public static function rotate(): string
    {
        Arr::forget($_SESSION, self::SESSION_KEY);

        return self::token();
    }

    /**
     * Read the token a request presents, preferring headers over the body field.
     */
    public static function fromRequest(ServerRequestInterface $request): ?string
    {
        foreach (self::HEADERS as $header) {
            $value = trim($request->getHeaderLine($header));

            if ($value !== '') {
                return $value;
            }
        }

        $body = $request->getParsedBody();

        if (!is_array($body)) {
            return null;
        }

        $field = Arr::get($body, self::FIELD_NAME);

        return is_string($field) && $field !== '' ? $field : null;
    }

    /**
     * Does the presented token match the session's? Compared in constant time.
     */
    public static function matches(?string $candidate): bool
    {
        if (!is_string($candidate) || $candidate === '') {
            return false;
        }

        return hash_equals(self::token(), $candidate);
    }
}
