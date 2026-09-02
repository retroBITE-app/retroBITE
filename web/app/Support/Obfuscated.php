<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Light reversible obfuscation for credentials that must ship with the source.
 *
 * This is NOT encryption - anyone with the repo can recover the plaintext. Its only
 * job is to keep application-level provider credentials out of plaintext code search
 * and scraper bots, which is what ScreenScraper's API terms ask of client developers.
 * Never use it for per-user secrets or anything that belongs in the environment.
 *
 */
final class Obfuscated
{
    private const KEY = 'retroBITE';

    public static function reveal(string $encoded): string
    {
        return self::cipher((string) base64_decode($encoded, true));
    }

    public static function conceal(string $plain): string
    {
        return base64_encode(self::cipher($plain));
    }

    /** XOR against a repeating key — symmetric, so it both conceals and reveals. */
    private static function cipher(string $input): string
    {
        $key    = self::KEY;
        $length = strlen($key);

        return Collection::make(str_split($input))
            ->map(fn(string $char, int $i) => chr(ord($char) ^ ord($key[$i % $length])))
            ->implode('');
    }
}
