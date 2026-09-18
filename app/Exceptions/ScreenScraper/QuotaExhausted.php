<?php

declare(strict_types=1);

namespace App\Exceptions\ScreenScraper;

use Carbon\CarbonImmutable;

/**
 * HTTP 430 — the daily scrape quota is spent (20 000 on a plain account).
 *
 * Resets at midnight Paris time, which is where ScreenScraper's day boundary
 * sits regardless of where the server running this is.
 */
class QuotaExhausted extends ScreenScraperException
{
    public function retryable(): bool
    {
        return true;
    }

    public function retryAfter(): ?int
    {
        $now = CarbonImmutable::now('Europe/Paris');

        // A minute past midnight, so a clock a few seconds out does not land
        // the retry on the wrong side of the reset and spend another day's
        // first request discovering the same thing.
        return max(60, (int) $now->addDay()->startOfDay()->addMinute()->diffInSeconds($now, absolute: true));
    }
}
