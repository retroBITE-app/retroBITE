<?php

declare(strict_types=1);

namespace App\Exceptions\ScreenScraper;

/**
 * HTTP 429 — too many concurrent requests, or too many within a minute.
 *
 * A plain registered account gets maxthreads = 1, so this should never fire
 * when the scraper queue runs a single worker. If it does, something else is
 * using the same credentials.
 */
final class ThreadLimitReached extends ScreenScraperException
{
    public function retryable(): bool
    {
        return true;
    }

    public function retryAfter(): int
    {
        return 60;
    }
}
