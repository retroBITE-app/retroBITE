@props([
    'heading' => '',
    'subheading' => '',
    // A screen's own control for the header row — a save button, usually.
    'actions' => null,
    // Drop the reading-width cap. For a screen laid out as a grid of cards
    // rather than a column of fields, where the cap only wastes the page.
    'wide' => false,
    // The tab strip across the top. Off where a screen is embedded outside
    // Settings — onboarding, which walks through three of them in turn.
    'showTabs' => true,
])

@php
    // Not request()->routeIs(): a Save re-renders this over Livewire's own
    // update route, which none of the tabs match, and the underline vanished.
    // The page's URL rides in the component snapshot, so the route is matched
    // from that instead.
    $route = request()->route();

    if (Livewire\Livewire::isLivewireRequest()) {
        try {
            $route = app('router')->getRoutes()->match(Illuminate\Http\Request::create(Livewire\Livewire::originalUrl()));
        } catch (Symfony\Component\HttpKernel\Exception\HttpException) {
            $route = null;
        }
    }

    $routeIs = function (string $pattern) use ($route): bool {
        return $route?->named($pattern) ?? false;
    };

    $tabs = [
        ['label' => __('User'), 'route' => 'user.edit', 'active' => $routeIs('user.*')],
        ['label' => __('UI'), 'route' => 'interface.edit', 'active' => $routeIs('interface.edit')],
        ['label' => __('Media'), 'route' => 'media.edit', 'active' => $routeIs('media.edit')],
        ['label' => __('Consoles'), 'route' => 'console-config.edit', 'active' => $routeIs('console-config.edit')],
        ['label' => __('Scraping'), 'route' => 'screenscraper.edit', 'active' => $routeIs('screenscraper.edit')],
        ['label' => __('Achievements'), 'route' => 'retroachievements.edit', 'active' => $routeIs('retroachievements.edit')],
        ['label' => __('Destinations'), 'route' => 'destinations.edit', 'active' => $routeIs('destinations.edit')],
    ];
@endphp

@if ($showTabs)
<div class="mb-6 flex gap-5.5 overflow-x-auto border-b border-raised">
    @foreach ($tabs as $tab)
        <a
            href="{{ route($tab['route']) }}"
            wire:navigate
            @class([
                'shrink-0 cursor-pointer pb-2.75 text-sm whitespace-nowrap transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep',
                'text-fg-bright shadow-underline' => $tab['active'],
                'text-fg-muted hover:text-fg-soft' => ! $tab['active'],
            ])
        >
            {{ $tab['label'] }}
        </a>
    @endforeach
</div>
@endif

@if (filled($heading) || filled($subheading) || filled($actions))
    {{-- The screen's action sits on the title's own line, hard right, rather
         than at the foot of a form somebody has to scroll past the whole pane
         to reach. It wraps under the text on a narrow window. --}}
    <div class="mb-5 flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
        <div class="min-w-0">
            @if (filled($heading))
                <p class="text-base text-fg-bright">{{ $heading }}</p>
            @endif

            @if (filled($subheading))
                <p class="mt-0.5 text-sm text-fg-faint">{{ $subheading }}</p>
            @endif
        </div>

        @if (filled($actions))
            <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
        @endif
    </div>
@endif

{{--
    The pane each settings screen lays itself out in.

    Twelve columns at lg, so a screen can put two cards side by side rather
    than stacking everything in one narrow strip with the page empty beside it.
    Each screen sets its own spans; the cap is what keeps a line of body text
    readable at that width, and `wide` is for the screens that have none to keep.
--}}
<div @class(['w-full', 'max-w-5xl' => ! $wide]) data-settings-fields>
    {{ $slot }}
</div>
