<?php

use App\Support\ScreenScraperQuota;
use Livewire\Component;

/**
 * What ScreenScraper has been asked for today, in the sidebar where a scan is
 * started from.
 *
 * Spent rather than left: it is the figure the bar underneath already draws,
 * and the two disagreed — the number fell as the bar filled. It is also the
 * one that answers the question somebody opens this for, which is how much a
 * scan has just cost, not how much room is left before it stops.
 *
 * One provider, not two. RetroAchievements reports no allowance of any kind —
 * there is no number to read — so what it had here was freshness wearing a
 * quota's clothes, and three rows of it in a column this narrow bought less
 * than it cost. How old each console's hash index is shows on the
 * RetroAchievements settings screen instead, which is where it gets asked.
 *
 * Of the two counters, only the successful one is drawn by default. They are
 * the same weight — the failed allowance is a tenth the size, 2 000 against
 * 20 000, and it is the one a library of unrecognised dumps actually exhausts
 * — but a footer that shows everything at once shows nothing, so the scarce
 * one is a fold away rather than always on screen.
 *
 * A component rather than a partial so that it can re-render on its own: the
 * layout is plain Blade and would otherwise freeze at whatever the last page
 * load said, which during a scan is exactly when the figures matter. It
 * re-renders when ScreenScraperQuota records a new snapshot — the only thing
 * that moves the figure — rather than on a timer.
 */
new class extends Component
{
    /**
     * Everything the panel draws, read again on every signal.
     *
     * In `with()` rather than in computed properties because all of it is read
     * once per render and none of it is shared with an action — a computed
     * property's memoisation would buy nothing and hide that.
     *
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $quota = ScreenScraperQuota::current();

        return [
            'quota' => $quota,

            // A snapshot is only ever written by a response, so an account that
            // was never filled in cannot produce one. Saying "no account" beats
            // a blank panel that looks like a provider outage.
            'scraperAccount' => App\Support\ScreenScraperCredentials::configured(),

            'requests' => $quota === null ? null : [
                'used' => $quota['requests_today'],
                'max' => $quota['max_requests_per_day'],
            ],

            'failed' => $quota === null ? null : [
                'used' => $quota['failed_today'],
                'max' => $quota['max_failed_per_day'],
            ],

            'closed' => $quota !== null && ($quota['closed_for_non_members'] || $quota['closed_for_leechers']),
        ];
    }
}; ?>

@php
    use Illuminate\Support\Number;
@endphp

{{--
    The heading row carries the figure, the way Storage does at the bottom of
    this column: one line that is both the label and the number, rather than a
    section title spending a row of its own on the word "APIs".
--}}
<x-sidebar.meter
    :label="__('Scraper')"
    :toggle-label="__('Show the failed-lookup allowance')"
    :disabled="$quota === null"
    x-data="{ open: $persist(false).as('sidebar.scraper') }"
    class="mb-3 border-b border-line pb-3"
>
    <x-slot:figure>
        @if ($requests === null)
            <span class="text-fg-faint">{{ __('—') }}</span>
        @elseif ($requests['max'] > 0)
            <x-fraction :value="Number::format($requests['used'])" :of="Number::format($requests['max'])" />
        @else
            <span class="text-fg-faint">{{ __('not reported') }}</span>
        @endif
    </x-slot:figure>

    <x-slot:summary>
        <x-live-system signal="quota" call="$refresh" class="hidden" />

        @if ($quota === null)
            <p class="mt-1.5 text-xs leading-snug text-fg-faint">
                {{ $scraperAccount
                    ? __('Not asked yet — figures appear after the first lookup.')
                    : __('No account set. Scanning works; identifying does not.') }}
            </p>
        @else
            <x-quota-bar :used="$requests['used']" :max="$requests['max']" class="mt-2" />

            {{--
                Load shedding at the far end. This is the whole reason to look
                before starting a scan, so it stays on screen rather than folding
                away with the second allowance.
            --}}
            @if ($closed)
                <x-notice tone="warn" icon="exclamation-triangle" size="sm" class="mt-2">
                    {{ $quota['closed_for_leechers']
                        ? __('Turning away accounts that only download.')
                        : __('Closed to non-members right now.') }}
                </x-notice>
            @endif
        @endif
    </x-slot:summary>

    @if ($quota !== null)
        <x-sidebar.meter-row :label="__('Failed')">
            @if ($failed['max'] > 0)
                <x-fraction :value="Number::format($failed['used'])" :of="Number::format($failed['max'])" />
            @else
                <span class="text-fg-faint">{{ __('not reported') }}</span>
            @endif

            <x-slot:bar>
                <x-quota-bar :used="$failed['used']" :max="$failed['max']" class="mt-1" />
            </x-slot:bar>
        </x-sidebar.meter-row>

        {{--
            The snapshot is written when a response arrives and never
            ages by itself. Without this, a figure from yesterday reads
            as today's.
        --}}
        @if ($quota['recorded_at'] !== '')
            <p class="font-mono text-[11px] leading-none text-fg-faint" title="{{ $quota['recorded_at'] }}">
                {{ __('read :ago', ['ago' => \Illuminate\Support\Carbon::parse($quota['recorded_at'])->diffForHumans(short: true)]) }}
            </p>
        @endif
    @endif
</x-sidebar.meter>
