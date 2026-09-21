<?php

use App\Services\RetroAchievementsService;
use App\Support\RetroAchievements\SyncFreshness;
use App\Support\ScreenScraperQuota;
use Livewire\Component;

/**
 * What the two providers will still answer, in the sidebar where a scan is
 * started from.
 *
 * The two halves are deliberately not the same shape. ScreenScraper reports an
 * allowance back in every response, so it can be drawn as what is left of
 * today. RetroAchievements reports nothing of the kind — there is no number to
 * read — so its half answers the only question its API can support: how current
 * the data we hold is. Two quota bars there would be invention.
 *
 * A component rather than a partial only so that it can poll: the layout is
 * plain Blade and would otherwise freeze at whatever the last page load said,
 * which during a scan is exactly when the figures matter. Sixty seconds
 * because that is what the numbers underneath are worth — the ScreenScraper
 * snapshot changes only when a response arrives, and SyncFreshness caches for
 * a minute anyway, so a shorter interval would re-render identical figures at
 * the cost of a request per open tab.
 */
new class extends Component
{
    /**
     * Everything the panel draws, refreshed on every poll.
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
            'scraperAccount' => (string) config('screenscraper.user', '') !== '',

            'bars' => $quota === null ? [] : [
                [
                    'label' => __('Requests'),
                    'used' => $quota['requests_today'],
                    'max' => $quota['max_requests_per_day'],
                ],
                // Same weight as the one above on purpose. It is a tenth the
                // size — 2 000 against 20 000 — and it is the one a library of
                // unrecognised dumps actually exhausts.
                [
                    'label' => __('Failed'),
                    'used' => $quota['failed_today'],
                    'max' => $quota['max_failed_per_day'],
                ],
            ],

            'closed' => $quota !== null && ($quota['closed_for_non_members'] || $quota['closed_for_leechers']),

            'freshness' => SyncFreshness::current(),

            'raLinked' => (string) (auth()->user()?->retroachievements_username ?? '') !== ''
                && app(RetroAchievementsService::class)->hasKey(),

            'progressSyncedAt' => auth()->user()?->retroachievements_synced_at,
        ];
    }
}; ?>

@php
    use Illuminate\Support\Number;
@endphp

<div wire:poll.60s class="mb-4 border-b border-line pb-4">
    <p class="kicker mb-2.5 text-fg-muted">{{ __('APIs') }}</p>

    <div class="flex flex-col gap-3.5">
        <div>
            <div class="flex items-baseline justify-between gap-2">
                <span class="text-xs text-fg-soft">{{ __('ScreenScraper') }}</span>

                {{--
                    The snapshot is written when a response arrives and never
                    ages by itself. Without this, a figure from yesterday reads
                    as today's.
                --}}
                @if ($quota !== null && $quota['recorded_at'] !== '')
                    <span class="font-mono text-xs whitespace-nowrap text-fg-faint" title="{{ $quota['recorded_at'] }}">
                        {{ \Illuminate\Support\Carbon::parse($quota['recorded_at'])->diffForHumans(short: true) }}
                    </span>
                @endif
            </div>

            @if ($closed)
                {{--
                    Load shedding at the far end. This is the whole reason to
                    look before starting a scan, so it sits above the figures
                    rather than reading as another statistic.
                --}}
                <p class="mt-2 flex items-start gap-1.5 rounded-md border border-warn/45 bg-warn/10 px-2 py-1.5 text-xs leading-snug text-warn">
                    <flux:icon.exclamation-triangle variant="micro" class="mt-px size-3.5 shrink-0" />
                    <span>{{ $quota['closed_for_leechers']
                        ? __('Turning away accounts that only download.')
                        : __('Closed to non-members right now.') }}</span>
                </p>
            @endif

            @if ($quota === null)
                <p class="mt-1.5 text-xs leading-snug text-fg-faint">
                    {{ $scraperAccount
                        ? __('Not asked yet — figures appear after the first lookup.')
                        : __('No account set. Scanning works; identifying does not.') }}
                </p>
            @else
                <div class="mt-2 flex flex-col gap-2">
                    @foreach ($bars as $bar)
                        @php
                            // Zero max means the response carried no limit for
                            // this counter. Not the same as a spent allowance,
                            // so it gets an empty track and no percentage.
                            $known = $bar['max'] > 0;
                            $left = $known ? max($bar['max'] - $bar['used'], 0) : null;
                            $percent = $known ? min((int) round($bar['used'] / $bar['max'] * 100), 100) : 0;
                        @endphp

                        <div>
                            <div class="flex items-baseline justify-between gap-2 text-xs">
                                <span class="text-fg-faint">{{ $bar['label'] }}</span>
                                <span class="font-mono whitespace-nowrap text-fg-dim">
                                    @if ($known)
                                        {{ Number::format($left) }} <span class="text-fg-faint">/ {{ Number::format($bar['max']) }}</span>
                                    @else
                                        <span class="text-fg-faint">{{ __('not reported') }}</span>
                                    @endif
                                </span>
                            </div>

                            <div class="mt-1 h-1 overflow-hidden rounded-sm bg-raised">
                                <div @class([
                                    'h-full rounded-sm transition-[width] duration-300',
                                    'bg-danger' => $percent >= 90,
                                    'bg-warn' => $percent >= 75 && $percent < 90,
                                    'bg-accent-deep' => $percent < 75,
                                ]) style="width: {{ $percent }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{--
                    Small, but it is the answer to why the scraper queue runs a
                    single worker: the account is allowed one thread.
                --}}
                <p class="mt-2 font-mono text-[11px] leading-none text-fg-faint">
                    {{ $quota['max_threads'] }} {{ trans_choice('thread|threads', $quota['max_threads']) }}
                    · {{ __('level :level', ['level' => $quota['level']]) }}
                </p>
            @endif
        </div>

        <div>
            <div class="flex items-baseline justify-between gap-2">
                <span class="text-xs text-fg-soft">{{ __('RetroAchievements') }}</span>

                @unless ($raLinked)
                    <a href="{{ route('retroachievements.edit') }}" wire:navigate class="font-mono text-xs text-fg-faint transition-colors hover:text-accent">
                        {{ __('not linked') }}
                    </a>
                @endunless
            </div>

            @if ($raLinked)
                {{--
                    Freshness, not quota: nothing in a RetroAchievements
                    response says what today's allowance is, and drawing a bar
                    off a number nobody reported would be a guess in the shape
                    of a fact.
                --}}
                <dl class="mt-1.5 flex flex-col gap-1 text-xs">
                    <div class="flex items-baseline justify-between gap-2">
                        <dt class="text-fg-faint">{{ __('Hash index') }}</dt>
                        <dd class="font-mono whitespace-nowrap text-fg-dim">
                            @if ($freshness['consoles'] === 0)
                                <span class="text-fg-faint">{{ __('nothing to index') }}</span>
                            @else
                                <span @class(['text-warn' => $freshness['indexed'] < $freshness['consoles']])>
                                    {{ $freshness['indexed'] }}/{{ $freshness['consoles'] }}
                                </span>
                                <span class="text-fg-faint">
                                    · {{ $freshness['indexed_at']?->diffForHumans(short: true) ?? __('never') }}
                                </span>
                            @endif
                        </dd>
                    </div>

                    <div class="flex items-baseline justify-between gap-2">
                        <dt class="text-fg-faint">{{ __('Progress') }}</dt>
                        <dd class="font-mono whitespace-nowrap text-fg-dim">
                            {{ $progressSyncedAt?->diffForHumans(short: true) ?? __('never') }}
                        </dd>
                    </div>

                    @if ($freshness['sets_pending'] > 0)
                        <div class="flex items-baseline justify-between gap-2">
                            <dt class="text-fg-faint">{{ __('Sets waiting') }}</dt>
                            <dd class="font-mono whitespace-nowrap text-warn">{{ Number::format($freshness['sets_pending']) }}</dd>
                        </div>
                    @endif
                </dl>
            @endif
        </div>
    </div>
</div>
