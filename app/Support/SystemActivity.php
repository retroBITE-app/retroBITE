<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What the queue workers are doing, in the vocabulary the sidebar speaks.
 *
 * The `jobs` table is the only thing that sees all of it. Most of the traffic
 * is opportunistic rather than fanned out from one known entry point — a match
 * dispatches artwork and an identification, an identification dispatches a set
 * sync, the schedule dispatches progress syncs — so bookkeeping kept alongside
 * would be wrong the first time somebody dispatched without telling it. One
 * grouped query over the table cannot be.
 *
 * Isolation in this application is by queue name rather than by connection
 * (see `config/queue.php`), so grouping by name is grouping by the work
 * itself. `hash` and `ra-hash` are folded into one row because the same two
 * workers serve both: they fill and drain together.
 *
 * Deliberately not cached, unlike the quota snapshot beside it in the sidebar.
 * An allowance changes only when a response arrives, so a minute of lag there
 * is invisible. Queue depth is the number somebody is watching move, and a
 * cached one would be wrong at exactly the moment it is looked at.
 */
final class SystemActivity
{
    /** The high-water marks, so a draining queue has a denominator. */
    private const PEAKS_KEY = 'system.activity.peaks';

    /**
     * Six hours. Long enough to outlive a spent ScreenScraper allowance
     * waiting for tomorrow, short enough that a run abandoned overnight is
     * measured against its own depth rather than against a number nothing
     * remembers the reason for.
     */
    private const PEAKS_TTL = 21600;

    /**
     * Queue names to what a person would call the work, in the order work
     * flows through the system, so the block reads downwards.
     *
     * @var array<string, array{label: string, queues: list<string>}>
     */
    private const GROUPS = [
        'scanning' => ['label' => 'Scanning', 'queues' => ['default']],
        'identifying' => ['label' => 'Identifying', 'queues' => ['scraper']],
        'hashing' => ['label' => 'Hashing', 'queues' => ['hash', 'ra-hash']],
        'artwork' => ['label' => 'Artwork', 'queues' => ['media']],
        'thumbnails' => ['label' => 'Thumbnails', 'queues' => ['thumbnails']],
        'achievements' => ['label' => 'Achievements', 'queues' => ['ra']],
        'progress' => ['label' => 'Progress', 'queues' => ['ra-progress']],
        'toolbox' => ['label' => 'Toolbox', 'queues' => ['toolbox']],
        'conversion' => ['label' => 'Toolbox - Conversion', 'queues' => ['toolbox-conversion']],
    ];

    /** @param  array<string, QueueActivity>  $queues */
    private function __construct(public readonly array $queues) {}

    public static function current(): self
    {
        $counts = self::counts();
        $peaks = self::peaks(array_map(
            fn (array $count): int => $count['running'] + $count['pending'] + $count['delayed'],
            $counts,
        ));

        $queues = [];

        foreach ($counts as $key => $count) {
            $queues[$key] = new QueueActivity(
                key: $key,
                label: $count['label'],
                running: $count['running'],
                pending: $count['pending'],
                delayed: $count['delayed'],
                peak: $peaks[$key] ?? 0,
            );
        }

        return new self($queues);
    }

    /**
     * Every queue, busy or not, in the order {@see GROUPS} lists them.
     *
     * What the sidebar draws once somebody unfolds it. A queue at zero is
     * worth a row there: the list is also the only place the application says
     * out loud what kinds of work exist, and a fold that shows nothing on an
     * idle system would be a control with no answer.
     *
     * @return list<QueueActivity>
     */
    public function all(): array
    {
        return array_values($this->queues);
    }

    /**
     * The queues with work in them, in the order {@see GROUPS} lists them.
     *
     * @return list<QueueActivity>
     */
    public function active(): array
    {
        return array_values(array_filter(
            $this->queues,
            fn (QueueActivity $queue): bool => $queue->busy(),
        ));
    }

    public function busy(): bool
    {
        return $this->active() !== [];
    }

    /**
     * Every queue added together, so the sidebar can draw one bar.
     *
     * A QueueActivity rather than a pair of integers because the rounding
     * rules that make a bar honest — never full while a job is left, nothing
     * at all before a denominator exists — belong in one place, and this is
     * the same kind of thing as the rows it sums.
     */
    public function total(): QueueActivity
    {
        return new QueueActivity(
            key: 'total',
            label: 'Activity',
            running: $this->sum(fn (QueueActivity $queue): int => $queue->running),
            pending: $this->sum(fn (QueueActivity $queue): int => $queue->pending),
            delayed: $this->sum(fn (QueueActivity $queue): int => $queue->delayed),
            peak: $this->sum(fn (QueueActivity $queue): int => $queue->peak),
        );
    }

    public function remaining(): int
    {
        return $this->sum(fn (QueueActivity $queue): int => $queue->remaining());
    }

    /** @param  callable(QueueActivity): int  $of */
    private function sum(callable $of): int
    {
        return array_sum(array_map($of, $this->queues));
    }

    public static function forget(): void
    {
        Cache::forget(self::PEAKS_KEY);
    }

    /**
     * Every group, whether or not it has work, keyed as GROUPS keys it.
     *
     * A queue name nobody listed gets an entry of its own under its raw name.
     * Dropping it would make the total smaller than the table, and a total
     * that does not add up is the one thing this class exists to prevent.
     *
     * @return array<string, array{label: string, running: int, pending: int, delayed: int}>
     */
    private static function counts(): array
    {
        $now = now()->getTimestamp();

        // One pass over the table. `reserved_at` and `available_at` are the
        // two columns that say what state a row is in, and both are plain
        // integers, so the whole split is three conditional sums.
        $rows = DB::table('jobs')
            ->selectRaw(
                'queue,'
                .' sum(reserved_at is not null) as `running`,'
                .' sum(reserved_at is null and available_at <= ?) as `pending`,'
                // Quoted because DELAYED is a reserved word in MariaDB, left
                // over from INSERT DELAYED. Unquoted it is a syntax error.
                .' sum(reserved_at is null and available_at > ?) as `delayed`',
                [$now, $now],
            )
            ->groupBy('queue')
            ->get()
            ->keyBy('queue');

        $counts = [];

        foreach (self::GROUPS as $key => $group) {
            $counts[$key] = ['label' => $group['label'], 'running' => 0, 'pending' => 0, 'delayed' => 0];

            foreach ($group['queues'] as $queue) {
                $row = $rows->pull($queue);

                if ($row === null) {
                    continue;
                }

                $counts[$key]['running'] += (int) $row->running;
                $counts[$key]['pending'] += (int) $row->pending;
                $counts[$key]['delayed'] += (int) $row->delayed;
            }
        }

        foreach ($rows as $queue => $row) {
            $counts[(string) $queue] = [
                'label' => (string) $queue,
                'running' => (int) $row->running,
                'pending' => (int) $row->pending,
                'delayed' => (int) $row->delayed,
            ];
        }

        return $counts;
    }

    /**
     * The deepest each queue has been during the run it is in.
     *
     * There is no batch total to measure a drain against — chains are not
     * batch members, every RetroAchievements job is `ShouldBeUnique` so a
     * batch's pending count would never reach zero, and half the dispatches
     * belong to no fan-out at all. What is left is to watch the depth and
     * remember the worst of it.
     *
     * @param  array<string, int>  $remaining
     * @return array<string, int>
     */
    private static function peaks(array $remaining): array
    {
        $stored = Cache::get(self::PEAKS_KEY);
        $stored = is_array($stored) ? $stored : [];

        $peaks = [];

        foreach ($remaining as $key => $left) {
            // An emptied queue drops its mark. That run is over, and the next
            // one is measured against its own size rather than the last one's.
            if ($left === 0) {
                continue;
            }

            $peaks[$key] = max($left, (int) ($stored[$key] ?? 0));
        }

        // Loose on purpose: same keys with same values is unchanged, whatever
        // order the store handed them back in. Writing only on a change keeps
        // a three-second poll from being a write every three seconds.
        if ($peaks != $stored) {
            $peaks === []
                ? Cache::forget(self::PEAKS_KEY)
                : Cache::put(self::PEAKS_KEY, $peaks, self::PEAKS_TTL);
        }

        return $peaks;
    }
}
