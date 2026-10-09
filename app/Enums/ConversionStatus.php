<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where one conversion is: waiting for the conversion worker, running,
 * checking what it wrote, putting it in its source's place, or over one of
 * three ways.
 *
 * Swapping is the record that a replacing conversion got as far as the
 * swap: everything it wrote was verified and placed, and the renames over
 * the sources have begun. Recovery after a restart finishes only a swap in
 * this state; any other, it puts back as it was.
 */
enum ConversionStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Verifying = 'verifying';
    case Swapping = 'swapping';
    case Done = 'done';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Queued => __('Queued'),
            self::Running => __('Running'),
            self::Verifying => __('Verifying'),
            self::Swapping => __('Replacing'),
            self::Done => __('Done'),
            self::Failed => __('Failed'),
            self::Cancelled => __('Cancelled'),
        };
    }

    /** Over, one way or another: nothing is working on it. */
    public function finished(): bool
    {
        return in_array($this, [self::Done, self::Failed, self::Cancelled], true);
    }

    /** A worker holds it right now. */
    public function active(): bool
    {
        return in_array($this, [self::Running, self::Verifying, self::Swapping], true);
    }

    /**
     * Whether asking it to stop still means anything. Not mid-swap: a few
     * renames, no tool to stop, and half a set swapped is worse than all of it.
     */
    public function cancellable(): bool
    {
        return ! $this->finished() && $this !== self::Swapping;
    }

    /**
     * Every status that is over, for a query: the one list, drawn from
     * finished() rather than written out again beside it.
     *
     * @return list<self>
     */
    public static function finishedCases(): array
    {
        return array_values(array_filter(self::cases(), function (self $status): bool {
            return $status->finished();
        }));
    }

    /** @return list<self> */
    public static function activeCases(): array
    {
        return array_values(array_filter(self::cases(), function (self $status): bool {
            return $status->active();
        }));
    }

    /** Whether it can be put back in the queue as it was. */
    public function retryable(): bool
    {
        return in_array($this, [self::Failed, self::Cancelled], true);
    }
}
