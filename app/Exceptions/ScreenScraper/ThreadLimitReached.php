<?php

declare(strict_types=1);

namespace App\Exceptions\ScreenScraper;

/**
 * HTTP 429 — too many concurrent requests, or too many within a minute.
 *
 * Also thrown before any request is made, when every one of the account's
 * thread slots stayed busy for `screenscraper.slot_wait` seconds (see
 * ScreenScraperService::paced()): more workers than threads, all at once.
 * From the provider itself it should not happen while the slots hold; if it
 * does, something else is using the same credentials.
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
