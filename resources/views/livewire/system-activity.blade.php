<?php

use App\Enums\ConversionStatus;
use App\Models\Conversion;
use App\Support\ExportProgress;
use App\Support\SystemActivity;
use Illuminate\Support\Arr;
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
        $done = array_sum(array_map(function (array $export): int {
            return min(Arr::get($export, 'done'), Arr::get($export, 'total'));
        }, $running));
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
@php($total = $activity->total())
@php(['at' => $convertingAt, 'total' => $convertingTotal, 'percent' => $convertingPercent] = $converting ?? ['at' => 0, 'total' => 0, 'percent' => 0])
@php(['busy' => $exportsBusy, 'done' => $exportsDone, 'total' => $exportsTotal, 'percent' => $exportsPercent] = $exports)

<x-sidebar.meter
    :label="__('Activity')"
    :toggle-label="__('Show each queue on its own')"
    x-data="{ open: $persist(false).as('sidebar.activity') }"
    class="mb-3 border-b border-line pb-3"
>
    <x-slot:figure>
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
    </x-slot:figure>

    {{--
        Every queue in one bar, measured against the deepest they have been
        since they last emptied, so a page opened mid-run starts at zero and
        climbs rather than inventing a total.
    --}}
    <x-slot:summary>
        <x-live-system :signal="['activity', 'conversion']" call="$refresh" class="hidden" />

        <x-progress-bar :percent="$total->percent()" :label="__('Queued work')" class="mt-2" />
    </x-slot:summary>

    {{--
        Every queue, not only the busy ones. A row at zero is worth its line:
        unfolded, this list is also the only place the interface says what
        kinds of work exist at all, and it would be a strange control that
        answered nothing on an idle system.
    --}}
    @foreach ($activity->all() as $queue)
        @php($writing = $queue->key === 'toolbox' && $exportsBusy)
        @php($convertingHere = $queue->key === 'conversion' && $converting !== null)

        <x-sidebar.meter-row
            wire:key="activity-{{ $queue->key }}"
            :label="__($queue->label)"
            :percent="$writing ? $exportsPercent : ($convertingHere ? $convertingPercent : $queue->percent())"
            :quiet="! $queue->busy() && ! $writing && ! $convertingHere"
        >
            @if ($writing)
                {{-- Files written out of files to write, where
                     the other rows count jobs left. --}}
                <x-fraction :value="$exportsDone" :of="$exportsTotal" />
            @elseif ($convertingHere)
                {{-- The conversion being worked on out of the batch, and how far the
                     batch is: a running disc moves the percent before
                     it moves the count. --}}
                <x-fraction :value="$convertingAt" :of="$convertingTotal" /> <span class="text-fg-faint">·</span> {{ $convertingPercent }}%
            @elseif ($queue->waiting())
                {{-- Everything left is scheduled for later: a
                     spent allowance, not a stuck queue. --}}
                <span class="text-fg-faint">{{ __('waiting') }}</span>
            @endif
            @unless ($writing || $convertingHere)
                {{ $queue->remaining() }}
            @endunless
        </x-sidebar.meter-row>
    @endforeach
</x-sidebar.meter>
