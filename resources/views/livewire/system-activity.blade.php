<?php

use App\Enums\ConversionStatus;
use App\Models\Conversion;
use App\Support\ExportProgress;
use App\Support\SystemActivity;
use Livewire\Component;

/**
 * What the queue workers are doing, in the sidebar, on every page.
 *
 * The panel above says what the two providers will still answer; this one says
 * what the machine is doing with those answers. Between them they cover the
 * whole of "is anything happening", which until now was visible only as a
 * three-queue line on the consoles page that ignored more work than it showed.
 *
 * A component rather than a partial for the same reason as its neighbour: the
 * layout is plain Blade and would otherwise freeze at whatever the last page
 * load said, which during a scan is exactly when the figure matters.
 */
new class extends Component
{
    /**
     * Everything the block draws, refreshed on every poll.
     *
     * In `with()` rather than a computed property, as next door: it is read
     * once per render and shared with no action, so memoisation would buy
     * nothing and hide that.
     *
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'activity' => SystemActivity::current(),
            'exports' => $this->exports(),
            'converting' => $this->converting(),
        ];
    }

    /**
     * How far the conversion queue is, for the Toolbox - Conversion row: one job there can
     * be an hour of one disc, and "1" says nothing about it.
     *
     * Counted over the batch — every conversion queued, or queued again by a
     * retry, since the oldest one still waiting or running — so a queue of five reads 3/5 while
     * the third runs, and the percent is the whole batch's: the finished ones in full, the
     * running ones as far as they have got. Null when nothing is left to do.
     *
     * @return array{at: int, total: int, percent: int}|null
     */
    private function converting(): ?array
    {
        $since = Conversion::query()->unfinished()->min('queued_at');

        if ($since === null) {
            return null;
        }

        $finished = array_map(function (ConversionStatus $status): string {
            return $status->value;
        }, ConversionStatus::finishedCases());
        $active = array_map(function (ConversionStatus $status): string {
            return $status->value;
        }, ConversionStatus::activeCases());

        // Counted in the database, one row back, rather than the whole batch.
        $batch = Conversion::query()
            ->toBase()
            ->where('queued_at', '>=', $since)
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when status in ('.implode(', ', array_fill(0, count($finished), '?')).') then 1 else 0 end) as done', $finished)
            ->selectRaw('sum(case when status in ('.implode(', ', array_fill(0, count($active), '?')).') then 1 else 0 end) as active', $active)
            ->selectRaw('sum(case when status in ('.implode(', ', array_fill(0, count($active), '?')).') then progress else 0 end) as running', $active)
            ->first();

        $total = (int) data_get($batch, 'total', 0);
        $done = (int) data_get($batch, 'done', 0);
        $running = (float) data_get($batch, 'running', 0);

        return [
            // The one being worked on, counted: finished plus running.
            'at' => min($total, $done + (int) data_get($batch, 'active', 0)),
            'total' => $total,
            'percent' => (int) min(100, round(100 * ($done + $running / 100) / max(1, $total))),
        ];
    }

    /**
     * Loader exports being written, counted in files rather than jobs.
     *
     * Shown on the Toolbox row while one runs: one job writes a whole
     * console, so the queue alone can only say "1" while nineteen files go
     * past. The worker leaves its count in ExportProgress, and that is the
     * figure worth showing. One not yet picked up has no count, so the row
     * shows the queue's jobs until it has. Two at once are added together,
     * the way the queues are in the bar above.
     *
     * @return array{busy: bool, done: int, total: int, percent: int}
     */
    private function exports(): array
    {
        $running = ExportProgress::all();
        $done = array_sum(array_map(fn (array $export): int => min($export['done'], $export['total']), $running));
        $total = array_sum(array_column($running, 'total'));

        return [
            'busy' => $running !== [],
            'done' => $done,
            'total' => $total,
            'percent' => $total > 0 ? (int) round(100 * $done / $total) : 0,
        ];
    }

}; ?>

{{--
    Re-rendered when the server says the queues moved, rather than polled. The
    signal comes from a listener on the queue itself (AppServiceProvider), so a
    scan started from the command line or the nightly schedule shows up too,
    and an idle page asks the database nothing at all.
--}}
<div
    x-data="{
        open: $persist(false).as('sidebar.activity'),
        stop: null,
        init() {
            const refresh = () => this.$wire.$refresh()
            const stops = [live.system('activity', refresh), live.system('conversion', refresh)]
            this.stop = () => stops.forEach((stop) => stop?.())
        },
        destroy() { this.stop?.() },
    }"
    class="mb-3 border-b border-line pb-3"
>
    @php($total = $activity->total())

    <button
        type="button"
        x-on:click="open = ! open"
        x-bind:aria-expanded="open"
        aria-label="{{ __('Show each queue on its own') }}"
        class="flex w-full cursor-pointer items-baseline justify-between gap-2 text-fg-muted transition-colors hover:text-fg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
    >
        <span class="kicker">{{ __('Activity') }}</span>

        <span class="flex items-baseline gap-1.5 font-mono text-xs whitespace-nowrap">
            @if ($activity->busy())
                {{-- size-3.5 rather than size-4: it sits on a text-xs baseline
                     and the larger glyph overhangs the row in a column this
                     narrow. --}}
                <flux:icon.arrow-path class="size-3.5 shrink-0 self-center animate-spin text-accent" />
                <span class="text-accent">{{ $total->remaining() }}</span>
            @else
                {{-- Kept rather than collapsed, so the footer does not jump the
                     moment a scan starts. Idle is also an answer. --}}
                <span class="text-fg-faint">{{ __('idle') }}</span>
            @endif

            <flux:icon.chevron-down
                class="size-3 shrink-0 self-center text-fg-faint transition-transform duration-200"
                x-bind:class="open && '-rotate-180'"
            />
        </span>
    </button>

    {{--
        Every queue in one bar, measured against the deepest they have been
        since they last emptied, so a page opened mid-run starts at zero and
        climbs rather than inventing a total.
    --}}
    <div class="mt-2 h-1 overflow-hidden rounded-sm bg-raised">
        <div
            class="h-full rounded-sm bg-accent-deep transition-[width] duration-300"
            style="width: {{ $total->percent() }}%"
        ></div>
    </div>

    {{--
        Every queue, not only the busy ones. A row at zero is worth its line:
        unfolded, this list is also the only place the interface says what
        kinds of work exist at all, and it would be a strange control that
        answered nothing on an idle system.

        Plain x-show rather than x-collapse: this component re-renders on a
        timer, and morphing a subtree whose inline height the collapse plugin
        owns is a flicker at exactly the moment somebody is watching the
        figures move.
    --}}
    <div x-show="open" x-cloak>
        <div class="mt-2.5 flex flex-col gap-2">
            @php(['at' => $convertingAt, 'total' => $convertingTotal, 'percent' => $convertingPercent] = $converting ?? ['at' => 0, 'total' => 0, 'percent' => 0])

            @foreach ($activity->all() as $queue)
                <div wire:key="activity-{{ $queue->key }}">
                    <div class="flex items-baseline justify-between gap-2 text-xs">
                        <span class="truncate text-fg-faint">{{ __($queue->label) }}</span>

                        @php($writing = $queue->key === 'toolbox' && $exports['busy'])
                        @php($convertingHere = $queue->key === 'conversion' && $converting !== null)

                        {{-- A quiet queue keeps its row but gives up the
                             brighter figure, so the busy ones are still the
                             ones the eye lands on. --}}
                        <span @class(['font-mono whitespace-nowrap', 'text-fg-dim' => $queue->busy() || $writing || $convertingHere, 'text-fg-faint' => ! $queue->busy() && ! $writing && ! $convertingHere])>
                            @if ($writing)
                                {{-- Files written out of files to write, where
                                     the other rows count jobs left. --}}
                                {{ $exports['done'] }}<span class="text-fg-faint">/{{ $exports['total'] }}</span>
                            @elseif ($convertingHere)
                                {{-- The conversion being worked on out of the batch, and how far the
                                     batch is: a running disc moves the percent before
                                     it moves the count. --}}
                                {{ $convertingAt }}<span class="text-fg-faint">/{{ $convertingTotal }} · </span>{{ $convertingPercent }}<span class="text-fg-faint">%</span>
                            @elseif ($queue->waiting())
                                {{-- Everything left is scheduled for later: a
                                     spent allowance, not a stuck queue. --}}
                                <span class="text-fg-faint">{{ __('waiting') }}</span>
                            @endif
                            @unless ($writing || $convertingHere)
                                {{ $queue->remaining() }}
                            @endunless
                        </span>
                    </div>

                    <div class="mt-1 h-1 overflow-hidden rounded-sm bg-raised">
                        <div
                            class="h-full rounded-sm bg-accent-deep transition-[width] duration-300"
                            style="width: {{ $writing ? $exports['percent'] : ($convertingHere ? $convertingPercent : $queue->percent()) }}%"
                        ></div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
