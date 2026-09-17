<?php

declare(strict_types=1);

namespace App\Exceptions\ScreenScraper;

/**
 * HTTP 403 — the developer credentials were rejected.
 */
final class BadCredentials extends ScreenScraperException
{
    public function retryable(): bool
    {
        return false;
    }
}
