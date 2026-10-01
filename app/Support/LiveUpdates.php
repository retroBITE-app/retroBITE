<?php

declare(strict_types=1);

namespace App\Support;

use App\Events\GameUpdated;
use App\Events\SystemUpdated;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one way the application tells open pages that something changed.
 *
 * A signal is a courtesy, never part of the work. Reverb can be down, a key
 * can be missing, or the command can be running on a host with no Reverb at
 * all; none of that may fail the identification, download or scan that sent
 * it. So every send is caught here, and a failure is logged once a minute
 * rather than once per job — a library-wide artwork run would otherwise write
 * a warning for every file.
 */
final class LiveUpdates
{
    /** How long a burst is, in seconds: as long as the browser folds signals for. */
    private const BURST = 1.0;

    /** @param  SystemUpdated::*  $what */
    public static function system(string $what): void
    {
        self::send(new SystemUpdated($what));
    }

    /**
     * The same signal, once per burst: the first now, any more within a
     * second held, and those that were held sent once as the process ends.
     *
     * For a loop that dispatches — a backfill queuing five thousand lookups,
     * the 03:30 achievement sync queuing hundreds of sets — where every job
     * queued would otherwise be a broadcast. The browser folds them into one
     * re-render a second anyway, but Reverb has to take each of them first,
     * and in dev print each with --debug; five thousand in two seconds is
     * what stopped it on 2026-10-01. The one at the end is what keeps the
     * page right: it counts after the last job was queued. A worker that
     * never ends sends again after the second is out, and its jobs' own
     * signals, which are not held, say the rest.
     *
     * @param  SystemUpdated::*  $what
     */
    public static function soon(string $what): void
    {
        $burst = self::burst();
        $now = microtime(true);

        if ($now - ($burst->sentAt[$what] ?? 0.0) >= self::BURST) {
            $burst->sentAt[$what] = $now;
            unset($burst->held[$what]);
            self::system($what);

            return;
        }

        $burst->held[$what] = true;

        if (! $burst->flushOnExit) {
            $burst->flushOnExit = true;
            app()->terminating(fn () => self::flush());
        }
    }

    /** Send what soon() held back. As the process ends; harmless to call twice. */
    public static function flush(): void
    {
        $burst = self::burst();
        /** @var list<SystemUpdated::*> $held */
        $held = array_keys($burst->held);
        $burst->held = [];

        foreach ($held as $what) {
            $burst->sentAt[$what] = microtime(true);
            self::system($what);
        }
    }

    /**
     * When soon() last sent each signal, and what it held since. The
     * application's rather than static, so it starts afresh with it.
     *
     * sentAt: what => when; held: what => true; flushOnExit: whether the
     * process will send what is held as it ends.
     */
    private static function burst(): \stdClass
    {
        if (! app()->bound(self::class.'.burst')) {
            app()->instance(self::class.'.burst', (object) ['sentAt' => [], 'held' => [], 'flushOnExit' => false]);
        }

        return app(self::class.'.burst');
    }

    /** @param  GameUpdated::*  $what */
    public static function game(int $gameId, string $what): void
    {
        self::send(new GameUpdated($gameId, $what));
    }

    private static function send(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $e) {
            if (Cache::add('live-updates.failure-logged', true, 60)) {
                Log::warning('Live update not sent; open pages will not refresh until Reverb is back.', [
                    'event' => $event::class,
                    'reason' => $e->getMessage(),
                ]);
            }
        }
    }
}
