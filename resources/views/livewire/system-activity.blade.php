<?php

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
        return ['activity' => SystemActivity::current()];
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
    dropping the attribute when idle the way the consoles page does. That page
    can afford to: it starts the work itself, so it knows when to begin
    watching. This one does not — a scan started from the command line, or by
    the nightly schedule, would never appear — so idle costs one grouped count
    of a small table every fifteen seconds, and Livewire stops even that while
    the tab is in the background.
--}}
<div wire:poll.{{ $activity->busy() ? '3s' : '15s' }} class="mb-4 border-b border-line pb-4">
    <div class="mb-2.5 flex items-baseline justify-between gap-2">
        <p class="kicker text-fg-muted">{{ __('Activity') }}</p>

        @if ($activity->busy())
            {{-- size-3.5 rather than size-4: it sits on a text-xs baseline and
                 the larger glyph overhangs the row in a column this narrow. --}}
            <span class="flex items-center gap-1.5 font-mono text-xs whitespace-nowrap text-accent">
                <flux:icon.arrow-path class="size-3.5 animate-spin" />
                {{ $activity->remaining() }}
            </span>
        @else
            {{-- Kept rather than collapsed, so the footer does not jump the
                 moment a scan starts. Idle is also an answer. --}}
            <span class="font-mono text-xs whitespace-nowrap text-fg-faint">{{ __('idle') }}</span>
        @endif
    </div>

    @if ($activity->busy())
        <div class="flex flex-col gap-2">
            @foreach ($activity->active() as $queue)
                <div wire:key="activity-{{ $queue->key }}">
                    <div class="flex items-baseline justify-between gap-2 text-xs">
                        <span class="truncate text-fg-faint">{{ __($queue->label) }}</span>

                        <span class="font-mono whitespace-nowrap text-fg-dim">
                            @if ($queue->waiting())
                                {{-- Everything left is scheduled for later: a
                                     spent allowance, not a stuck queue. --}}
                                <span class="text-fg-faint">{{ __('waiting') }}</span>
                            @endif
                            {{ $queue->remaining() }}
                        </span>
                    </div>

                    {{-- Measured against the deepest this queue has been since
                         it last emptied, so a page opened mid-run starts at
                         zero and climbs rather than inventing a total. --}}
                    <div class="mt-1 h-1 overflow-hidden rounded-sm bg-raised">
                        <div
                            class="h-full rounded-sm bg-accent-deep transition-[width] duration-300"
                            style="width: {{ $queue->percent() }}%"
                        ></div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
