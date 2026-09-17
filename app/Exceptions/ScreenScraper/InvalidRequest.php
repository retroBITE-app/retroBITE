<?php

declare(strict_types=1);

namespace App\Exceptions\ScreenScraper;

/**
 * HTTP 400 that is not a miss — a malformed request, which is our bug.
 *
 * The documented causes are a path where a bare filename belongs, a badly
 * formatted crc/md5/sha1, or a missing mandatory field. Retrying an identical
 * request always fails again, so this must not be swallowed as "not found".
 */
final class InvalidRequest extends ScreenScraperException
{
    public function retryable(): bool
    {
        return false;
    }
}
