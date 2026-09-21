<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One kind of work, and how much of it is outstanding.
 *
 * The three counters are kept apart because they mean different things to
 * somebody watching. `running` is a worker holding the row. `pending` is ready
 * and nobody has taken it yet. `delayed` is a job that put itself back: a
 * match releases with the delay ScreenScraper asked for, and a spent daily
 * allowance releases without even burning a try, so a queue can sit full of
 * rows that are doing exactly the right thing and will not move for hours.
 * Summed into one figure that reads as a stall; kept apart it reads as
 * waiting, which is what it is.
 *
 * `peak` is the deepest this queue has been seen during the run it is in the
 * middle of, and it is the only denominator available — see
 * {@see SystemActivity} for why there is no batch total to use instead.
 */
final class QueueActivity
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly int $running,
        public readonly int $pending,
        public readonly int $delayed,
        public readonly int $peak = 0,
    ) {}

    public function remaining(): int
    {
        return $this->running + $this->pending + $this->delayed;
    }

    public function busy(): bool
    {
        return $this->remaining() > 0;
    }

    /**
     * Everything left is scheduled for later.
     *
     * A queue nobody is working on because there is nothing to work on yet,
     * as against one nobody is working on at all.
     */
    public function waiting(): bool
    {
        return $this->running === 0 && $this->pending === 0 && $this->delayed > 0;
    }

    public function percent(): int
    {
        if ($this->peak <= 0) {
            return 0;
        }

        $percent = (int) round(($this->peak - $this->remaining()) / $this->peak * 100);

        // Never a full bar while a job is left. Rounding turns 499 done of 500
        // into 100, and a full bar over a queue that is still working reads as
        // a broken bar rather than as nearly finished.
        return $this->busy() ? max(min($percent, 99), 0) : 100;
    }
}
