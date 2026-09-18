<?php

declare(strict_types=1);

namespace App\Exceptions\ScreenScraper;

/**
 * HTTP 5xx, or a transport failure. Worth another try later.
 */
final class ServerError extends ScreenScraperException
{
    public function retryable(): bool
    {
        return true;
    }

    public function retryAfter(): int
    {
        return 300;
    }
}
