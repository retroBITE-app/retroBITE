<?php

use App\Support\ExportProgress;
use App\Support\SystemActivity;
use Livewire\Attributes\On;
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
        ];
    }

    /**
     * Loader exports being written, counted in files rather than jobs.
     *
     * Its own row rather than one of the queues': one job writes a whole
     * console, so the media queue can only say "1" while nineteen files go
     * past. The worker leaves its count in ExportProgress, and that is the
     * figure worth showing. One not yet picked up has no count and is already
     * in the artwork depth, so it is left to that row. Two at once are added
     * together, the way the queues are in the bar above.
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

    /**
     * Nudged by whatever just queued something — a dispatch without `to()`
     * reaches every component on the page — so starting a scan lights this up
     * at once instead of waiting out the idle tick. Nothing to do in the body:
     * handling the event is itself a re-render, and the figures are read fresh
     * in `with()` every time.
     */
    #[On('system-activity-changed')]
    public function recheck(): void {}
}; ?>

{{--
    Three seconds while there is work, fifteen while there is none, rather than
    dropping the attribute when idle. This block does not start the work it
    shows — a scan started from the command line, or by the nightly schedule,
    would never appear — so idle costs one grouped count
    of a small table every fifteen seconds, and Livewire stops even that while
    the tab is in the background.
--}}
<div
    wire:poll.{{ $activity->busy() ? '3s' : '15s' }}
    x-data="{ open: $persist(false).as('sidebar.activity') }"
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
            @foreach ($activity->all() as $queue)
                <div wire:key="activity-{{ $queue->key }}">
                    <div class="flex items-baseline justify-between gap-2 text-xs">
                        <span class="truncate text-fg-faint">{{ __($queue->label) }}</span>

                        {{-- A quiet queue keeps its row but gives up the
                             brighter figure, so the busy ones are still the
                             ones the eye lands on. --}}
                        <span @class(['font-mono whitespace-nowrap', 'text-fg-dim' => $queue->busy(), 'text-fg-faint' => ! $queue->busy()])>
                            @if ($queue->waiting())
                                {{-- Everything left is scheduled for later: a
                                     spent allowance, not a stuck queue. --}}
                                <span class="text-fg-faint">{{ __('waiting') }}</span>
                            @endif
                            {{ $queue->remaining() }}
                        </span>
                    </div>

                    <div class="mt-1 h-1 overflow-hidden rounded-sm bg-raised">
                        <div
                            class="h-full rounded-sm bg-accent-deep transition-[width] duration-300"
                            style="width: {{ $queue->percent() }}%"
                        ></div>
                    </div>
                </div>
            @endforeach

            {{-- Files written out of files to write, where the rows above
                 count jobs left. Zero when nothing is being written, like
                 any other quiet row. --}}
            <div wire:key="activity-exports">
                <div class="flex items-baseline justify-between gap-2 text-xs">
                    <span class="truncate text-fg-faint">{{ __('Exporting') }}</span>

                    <span @class(['font-mono whitespace-nowrap', 'text-fg-dim' => $exports['busy'], 'text-fg-faint' => ! $exports['busy']])>
                        @if ($exports['busy'])
                            {{ $exports['done'] }}<span class="text-fg-faint">/{{ $exports['total'] }}</span>
                        @else
                            0
                        @endif
                    </span>
                </div>

                <div class="mt-1 h-1 overflow-hidden rounded-sm bg-raised">
                    <div
                        class="h-full rounded-sm bg-accent-deep transition-[width] duration-300"
                        style="width: {{ $exports['percent'] }}%"
                    ></div>
                </div>
            </div>
        </div>
    </div>
</div>
