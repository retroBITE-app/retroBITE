<?php

declare(strict_types=1);

namespace App\Exceptions\ScreenScraper;

/**
 * HTTP 401 and 423 — the API is closed to us right now.
 *
 * 401 is the common one: ScreenScraper sheds non-members whenever its own CPU
 * passes 60 %, so it says nothing about our credentials despite the status
 * code. 423 means the service itself is in trouble. Both pass.
 */
final class ApiUnavailable extends ScreenScraperException
{
    public function retryable(): bool
    {
        return true;
    }

    public function retryAfter(): int
    {
        return 900;
    }
}
