<?php

declare(strict_types=1);

namespace App\Jobs\RetroAchievements;

use DateTimeInterface;

/**
 * A RetroAchievements job that waits as long as the API asks it to.
 *
 * Putting a job back to wait out a rate limit is not a failure, but the queue
 * counts each one as an attempt, and three were all a job had: the 03:30 sync
 * of hundreds of sets met Cloudflare's ten-minute block and lost every set it
 * had not reached, night after night. Bounded by time instead — half a day —
 * and by what actually went wrong: three exceptions and the job has failed.
 */
trait WaitsOutRateLimits
{
    /** Thrown, not put back: a release to wait is not one of these. */
    public int $maxExceptions = 3;

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(12);
    }
}
