<?php

declare(strict_types=1);

namespace App\Exceptions\ScreenScraper;

use RuntimeException;

/**
 * Base for every ScreenScraper failure that is not simply "no match".
 *
 * A miss is not an exception: it is an ordinary, expected answer and the caller
 * gets null. Everything in this hierarchy means the request did not get to ask
 * its question, which is a different thing entirely — and the distinction is
 * the whole point. Treating a spent quota as a miss silently marks a library
 * unmatched.
 */
abstract class ScreenScraperException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly string $body = '',
    ) {
        parent::__construct($message);
    }

    /**
     * Whether waiting and trying the same request again could succeed.
     *
     * False means the request itself is wrong, or we are barred — retrying is
     * pointless and burns quota.
     */
    abstract public function retryable(): bool;

    /**
     * How long to wait before the next attempt, in seconds, or null when the
     * caller should decide.
     */
    public function retryAfter(): ?int
    {
        return null;
    }
}
