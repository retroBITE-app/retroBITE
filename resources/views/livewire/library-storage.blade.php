<?php

use App\Support\LibraryStorage;
use Illuminate\Support\Number;
use Livewire\Component;

/**
 * How much room the library takes, at the foot of the sidebar.
 *
 * The two panels above say what the provider will still answer and what the
 * workers are doing with those answers; this one says where all of it lands.
 * A component rather than the lines of layout it replaces for the same reason
 * as its neighbours: the layout is plain Blade and would otherwise freeze at
 * whatever the last navigation said.
 *
 * Nothing here reads the disk: the used figure is a query over the identified
 * games, and the free space is what MeasureLibrary last measured. It listens
 * for SystemUpdated(storage), which that job and scans and exports send.
 */
new class extends Component
{
    /**
     * The one reading this block draws, read again on every signal and poll.
     *
     * In `with()` rather than a computed property, as next door: read once per
     * render and shared with no action, so memoisation would buy nothing and
     * would hide that.
     *
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return ['storage' => LibraryStorage::current()];
    }
}; ?>

{{--
    Re-rendered on the storage signal, and polled every five minutes as well:
    the used figure moves as games are identified, which sends no system
    signal, and the poll is a single cheap query. It is the one poll left. See
    docs/adr/0002-live-updates-over-reverb.md.
--}}
<div
    wire:poll.300s
    x-data="{
        stop: null,
        init() { this.stop = live.system('storage', () => this.$wire.$refresh()) },
        destroy() { this.stop?.() },
    }"
>
    {{--
        `kicker` goes on the label only. On the flex parent its 0.14em tracking
        also stretched the figure, which then wrapped.
    --}}
    <div class="flex items-baseline justify-between gap-2 text-fg-muted">
        <span class="kicker">{{ __('Storage') }}</span>

        @if ($storage === null)
            {{-- No mount, no reading. A bar at zero here would read as an empty
                 library, which is the one thing it is not known to be. --}}
            <span
                class="font-mono text-xs whitespace-nowrap text-fg-faint"
                title="{{ __('The library folder cannot be read from here.') }}"
            >{{ __('—') }}</span>
        @else
            {{-- One colour across both halves, as this line has always been
                 drawn. The panel above dims its denominator; matching it here
                 would be a restyle riding along on a wiring change. --}}
            <span
                class="font-mono text-xs whitespace-nowrap"
                title="{{ __(':free free on the drive', ['free' => Number::fileSize($storage->free, 1)]) }}"
            >{{ Number::fileSize($storage->used, 1) }} / {{ Number::fileSize($storage->total(), 1) }}</span>
        @endif
    </div>

    {{--
        The shared bar rather than the hand-rolled one this replaces: it owns
        the colour ramp, and this is the one reading in this column where
        crossing 90% is worth a colour — it does so exactly when the drive has
        no room left. Its geometry comes from the call site: mt-2 mb-3.5 is the
        gap down to the account row, which this block has no border to make.
    --}}
    <x-quota-bar :used="$storage?->used ?? 0" :max="$storage?->total() ?? 0" class="mt-2 mb-3.5" />
</div>
