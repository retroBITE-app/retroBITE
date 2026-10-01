@php
    // One grouped query rather than a count per console: the list below runs
    // on every page of the app. Identified games only, the same figure the
    // console cards and the shelf call Games.
    $gameCounts = App\Models\Game::query()
        ->where('status', App\Enums\GameStatus::Matched)
        ->selectRaw('console, count(*) as games')
        ->groupBy('console')
        ->pluck('games', 'console');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark" data-scheme="{{ App\Enums\ColorScheme::current()->value }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-ground">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-line bg-sunken">
            <flux:sidebar.header>
                <a href="{{ route('dashboard') }}" wire:navigate class="flex flex-1 items-center justify-center py-1">
                    <x-logo variant="logo-wide" :label="config('app.name')" class="block h-auto w-[132px]" />
                </a>
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            {{-- The Ctrl+K box, for somebody who does not know the shortcut. --}}
            <button
                type="button"
                x-data
                x-on:click="$dispatch('open-global-search')"
                aria-keyshortcuts="Control+K Meta+K"
                class="flex w-full cursor-pointer items-center gap-2 rounded-lg border border-line-input bg-ground/60 px-2.75 py-1.5 text-sm text-fg-dim transition-colors hover:border-line-bright hover:text-fg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
            >
                <flux:icon.magnifying-glass class="size-3.5" />
                <span class="flex-1 text-left">{{ __('Search') }}</span>
                <kbd aria-hidden="true" class="font-sans text-xs">⌘K</kbd>
            </button>

            @php($installed = App\Models\ConsoleSourceFolder::consoles())

            <flux:sidebar.nav>
                <flux:sidebar.item icon="squares-2x2" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                    {{ __('Dashboard') }}
                </flux:sidebar.item>

                <flux:sidebar.item icon="puzzle-piece" :href="route('consoles.index')" :current="request()->routeIs('consoles.index')" wire:navigate>
                    {{ __('Consoles') }}
                </flux:sidebar.item>

                @if ($installed->isNotEmpty())
                    {{--
                        Installed consoles hang off `Consoles` as its children
                        rather than standing as their own section. Geometry
                        copies flux:sidebar.group's expandable branch — ps-7 on
                        the list, a hairline rail at ms-5 — so the indent reads
                        the same as Flux's own nesting.
                    --}}
                    <div class="relative min-w-0 ps-7">
                        <div class="absolute inset-y-[3px] start-0 ms-5 w-px bg-line-strong"></div>

                        <div class="flex flex-col gap-px">
                            @foreach ($installed as $console)
                                {{-- A game is inside its console now, so its page lights the console too. --}}
                                @php($isCurrent = request()->routeIs('consoles.games', 'games.show') && request()->route('console') === $console->key)

                                <a
                                    href="{{ route('consoles.games', ['console' => $console->key]) }}"
                                    wire:navigate
                                    @class([
                                        'flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm transition-colors',
                                        'bg-accent-tint/13 text-accent shadow-rail' => $isCurrent,
                                        'text-fg-cool hover:bg-hover hover:text-fg' => ! $isCurrent,
                                    ])
                                >
                                    <img src="{{ $console->icon }}" alt="" class="size-4 shrink-0 object-contain" />
                                    <span class="truncate">{{ $console->name }}</span>
                                    <span class="ms-auto font-mono text-xs text-fg-faint">{{ Illuminate\Support\Arr::get($gameCounts, $console->key, 0) }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- The all-games list only. A game's own page lives under its
                     console and lights that console instead, whichever list it
                     was opened from. --}}
                <flux:sidebar.item icon="rectangle-stack" :href="route('games.index')" :current="request()->routeIs('games.index')" wire:navigate>
                    {{ __('Games') }}
                </flux:sidebar.item>

                {{-- Tools opens and closes, and stands open while one of its
                     pages is showing. Its items are drawn exactly as the
                     consoles under Consoles are — the group's rail and indent
                     come from the published flux/sidebar/group view — so the
                     two nested lists read as one kind of thing. The next tool
                     is another link here. --}}
                <flux:sidebar.group expandable icon="wrench-screwdriver" :heading="__('Tools')" :expanded="request()->routeIs('tools.*')">
                    @php($isCurrent = request()->routeIs('tools.conversion'))

                    <a
                        href="{{ route('tools.conversion') }}"
                        wire:navigate
                        @class([
                            'flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm transition-colors',
                            'bg-accent-tint/13 text-accent shadow-rail' => $isCurrent,
                            'text-fg-cool hover:bg-hover hover:text-fg' => ! $isCurrent,
                        ])
                    >
                        <flux:icon.arrows-right-left class="size-4 shrink-0" />
                        <span class="truncate">{{ __('Conversion') }}</span>
                    </a>
                </flux:sidebar.group>

                <flux:sidebar.item icon="book-open" :href="route('docs.index')" :current="request()->routeIs('docs.*')" wire:navigate>
                    {{ __('Docs') }}
                </flux:sidebar.item>

                <flux:sidebar.item icon="adjustments-horizontal" :href="route('user.edit')" :current="request()->routeIs('user.*') || request()->routeIs('media.edit') || request()->routeIs('interface.edit')" wire:navigate>
                    {{ __('Settings') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>

            <flux:spacer />

            <div class="mt-auto border-t border-line pt-4">
                {{--
                    Three readings in one grammar: what the provider will still
                    answer, what the workers are doing with those answers, and
                    the disk it all lands on. Label and figure on one line, one
                    bar under it, detail a fold away. All three are components
                    rather than partials because they poll; the rest of this
                    layout is static until the next navigation.
                --}}
                <livewire:api-status />

                <livewire:system-activity />

                <livewire:library-storage />

                <div class="flex items-center gap-2.5">
                    <x-logo class="block h-auto w-10 shrink-0" />

                    <span class="min-w-0 flex-1 leading-tight">
                        <span class="block truncate text-sm text-fg-soft">{{ auth()->user()->username ?? auth()->user()->name }}</span>
                        <span class="block font-mono text-xs text-fg-faint">v{{ config('app.version') }}</span>
                    </span>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button
                            type="submit"
                            data-test="logout-button"
                            aria-label="{{ __('Log out') }}"
                            class="cursor-pointer rounded text-fg-dim transition-colors hover:text-fg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
                        >
                            <flux:icon.arrow-right-start-on-rectangle variant="micro" />
                        </button>
                    </form>
                </div>
            </div>
        </flux:sidebar>

        {{--
            Floating drawer toggle, as in the previous build. No header bar: the
            sidebar is the only navigation, so a full-width chrome strip just to
            hold one button was dead space.

            Hand-rolled rather than flux:sidebar.toggle because that renders a
            flux:button whose own fill and border fight this treatment. It
            dispatches the same event the sidebar listens for.
        --}}
        <button
            type="button"
            x-data
            x-on:click="$dispatch('flux-sidebar-toggle')"
            aria-label="{{ __('Toggle sidebar') }}"
            data-sidebar-toggle
            class="fixed top-4 left-4 z-30 grid h-10 w-10 place-items-center rounded-lg border border-line-bright bg-surface/90 text-fg-soft backdrop-blur-sm transition-colors hover:text-fg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep lg:hidden"
        >
            <flux:icon.bars-2 class="size-5" />
        </button>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        {{-- Ctrl+K from any page, open at once after a wire:navigate. --}}
        @persist('global-search')
            <livewire:global-search />
        @endpersist

        {{-- A USB transfer goes on from page to page; see transfer-tray. --}}
        @persist('transfer-tray')
            <x-transfer-tray />
        @endpersist

        @fluxScripts
    </body>
</html>
