<?php

declare(strict_types=1);

namespace App\Exceptions\RetroAchievements;

/**
 * HTTP 429 — too many requests.
 *
 * Cloudflare's, in front of the API ("error code: 1015"), and it says how
 * long in Retry-After: ten minutes is ordinary. Asking again before then is
 * refused too, and may be what keeps the block going, so the wait is the
 * header's whenever it sent one — the 120 seconds otherwise are a guess.
 */
final class RateLimited extends RetroAchievementsException
{
    public function __construct(
        string $message,
        int $status = 429,
        string $body = '',
        private readonly ?int $wait = null,
        public readonly bool $asked = true,
    ) {
        parent::__construct($message, $status, $body);
    }

    /** Not asked at all: an earlier answer's block is still on (RetroAchievementsService). */
    public static function stillBlocked(int $seconds): self
    {
        return new self('Rate limited; waiting out the block before asking again.', 429, '', $seconds, asked: false);
    }

    public function retryable(): bool
    {
        return true;
    }

    public function retryAfter(): int
    {
        return max(1, $this->wait ?? 120);
    }
}
