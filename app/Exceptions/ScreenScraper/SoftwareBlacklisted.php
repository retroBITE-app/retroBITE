<?php

declare(strict_types=1);

namespace App\Exceptions\ScreenScraper;

/**
 * HTTP 426 — this softname is blacklisted or too old a version.
 *
 * Retrying cannot fix it and every attempt digs the hole deeper; it needs a
 * human to talk to ScreenScraper.
 */
final class SoftwareBlacklisted extends ScreenScraperException
{
    public function retryable(): bool
    {
        return false;
    }
}
