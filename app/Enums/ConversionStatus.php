<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where one conversion is: waiting for the conversion worker, running,
 * checking what it wrote, or over one of three ways.
 */
enum ConversionStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Verifying = 'verifying';
    case Done = 'done';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Queued => __('Queued'),
            self::Running => __('Running'),
            self::Verifying => __('Verifying'),
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
        return in_array($this, [self::Running, self::Verifying], true);
    }

    /** Whether asking it to stop still means anything. */
    public function cancellable(): bool
    {
        return ! $this->finished();
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
