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
<x-live-system signal="storage" call="$refresh" wire:poll.300s>
    <x-sidebar.meter :label="__('Storage')" :collapsible="false">
        <x-slot:figure>
            @if ($storage === null)
                {{-- No mount, no reading. A bar at zero here would read as an empty
                     library, which is the one thing it is not known to be. --}}
                <span class="text-fg-faint" title="{{ __('The library folder cannot be read from here.') }}">{{ __('—') }}</span>
            @else
                <x-fraction
                    :value="Number::fileSize($storage->used, 1)"
                    :of="Number::fileSize($storage->total(), 1)"
                    title="{{ __(':free free on the drive', ['free' => Number::fileSize($storage->free, 1)]) }}"
                />
            @endif
        </x-slot:figure>

        {{--
            The shared bar rather than a hand-rolled one: it owns the colour
            ramp, and this is the one reading in this column where crossing 90%
            is worth a colour — it does so exactly when the drive has no room
            left. Its geometry comes from the call site: mt-2 mb-3.5 is the gap
            down to the account row, which this block has no border to make.
        --}}
        <x-slot:summary>
            <x-quota-bar :used="$storage?->used ?? 0" :max="$storage?->total() ?? 0" :label="__('Library storage used')" class="mt-2 mb-3.5" />
        </x-slot:summary>
    </x-sidebar.meter>
</x-live-system>
