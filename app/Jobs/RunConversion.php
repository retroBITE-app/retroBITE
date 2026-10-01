<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Conversion\ConversionRunner;
use App\Models\Conversion;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Run one conversion; see {@see ConversionRunner} for what that is.
 *
 * On a queue and worker of its own, toolbox-conversion, so a disc that takes
 * an hour never holds a loader export back; over database-long, because a
 * conversion runs for up to ConversionRunner::timeout() seconds — 7000 by
 * default, and always kept under that connection's retry_after — where the
 * half-hour retry_after of the default connection would hand one still
 * running to a second worker. The row carries the state, so the job only has
 * to be told which row, and never runs twice beside itself for one row.
 *
 * How many run at once is config('converters.concurrency'), held by one lock
 * per slot rather than by the number of workers, so raising the conversion
 * workers alone does not double the conversions. A conversion that
 * finds every slot taken goes back on the queue for a moment. Released rather
 * than failed, so attempts are unlimited; one exception fails it, though.
 */
class RunConversion implements ShouldQueue
{
    use Queueable;

    /** Seconds a slot is held at most: past the conversion's own limit, for a worker that died. */
    private const SLOT_MARGIN = 120;

    public int $timeout;

    public int $tries = 0;

    public int $maxExceptions = 1;

    /** Killed at its timeout, it goes straight to failed() and the row says so, rather than waiting out retry_after. */
    public bool $failOnTimeout = true;

    public function __construct(public readonly int $conversionId)
    {
        // A minute past the runner's own limit, so it stops the tool and
        // records why before the worker is killed with it still running.
        $this->timeout = ConversionRunner::timeout() + 60;

        $this->onConnection('database-long')->onQueue('toolbox-conversion');
    }

    /**
     * One job at a time per conversion. A second job for the same row — a
     * Retry pressed while an earlier job still waited for a slot, or one
     * handed out again while the first still runs — is dropped rather than
     * let loose on a run in progress.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->conversionId))
                ->dontRelease()
                ->expireAfter($this->timeout + self::SLOT_MARGIN),
        ];
    }

    public function handle(ConversionRunner $runner): void
    {
        $conversion = Conversion::query()->find($this->conversionId);

        if ($conversion === null || $conversion->status->finished()) {
            return;
        }

        // Handed out again with the row still saying it runs: the worker that
        // had it is gone. Say so rather than start again from nothing.
        if ($conversion->status->active()) {
            $runner->abandon($conversion);

            return;
        }

        $slot = $this->slot();

        if ($slot === null) {
            $this->release(15);

            return;
        }

        try {
            $runner->run($conversion);
        } finally {
            $slot->release();
        }
    }

    /** Killed at its timeout, or failed some way run() did not catch. */
    public function failed(?Throwable $e): void
    {
        $conversion = Conversion::query()->find($this->conversionId);

        if ($conversion !== null && ! $conversion->status->finished()) {
            app(ConversionRunner::class)->abandon($conversion);
        }
    }

    /** One free slot, held, or null when all are taken. */
    private function slot(): ?Lock
    {
        $slots = max(1, (int) config('converters.concurrency', 1));

        for ($i = 1; $i <= $slots; $i++) {
            $lock = Cache::lock('conversion.slot.'.$i, $this->timeout + self::SLOT_MARGIN);

            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }
}
