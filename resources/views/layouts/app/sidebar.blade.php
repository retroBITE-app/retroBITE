@php
    // One grouped query rather than a count per console: the list below runs
    // on every page of the app.
    $gameCounts = App\Models\Game::query()
        ->selectRaw('console, count(*) as games')
        ->groupBy('console')
        ->pluck('games', 'console');

    // Placeholder figures, until something measures the disk.
    $storageUsed = '412 GB';
    $storageTotal = '1.8 TB';
    $storagePercent = 23;
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-ground">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-line bg-sunken">
            <flux:sidebar.header>
                <a href="{{ route('dashboard') }}" wire:navigate class="flex flex-1 items-center justify-center py-1">
                    <img src="/images/logo.webp" alt="{{ config('app.name') }}" class="block h-auto w-[132px]" />
                </a>
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.item icon="squares-2x2" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                    {{ __('Dashboard') }}
                </flux:sidebar.item>

                <flux:sidebar.item icon="wrench-screwdriver" :href="route('builder.index')" :current="request()->routeIs('builder.*')" wire:navigate>
                    {{ __('Builder') }}
                </flux:sidebar.item>

                <flux:sidebar.item icon="book-open" :href="route('docs.index')" :current="request()->routeIs('docs.*')" wire:navigate>
                    {{ __('Docs') }}
                </flux:sidebar.item>

                <flux:sidebar.item icon="adjustments-horizontal" :href="route('user.edit')" :current="request()->routeIs('user.*') || request()->routeIs('media.edit')" wire:navigate>
                    {{ __('Settings') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>

            {{--
                Consoles and Games are the library itself rather than app
                chrome, so they sit under their own kicker — the same pattern
                as `Installed` below, which lists what that library contains.
            --}}
            <p class="kicker px-3 pt-4 -pb-5 text-fg-faint">{{ __('Library') }}</p>

            <flux:sidebar.nav>
                <flux:sidebar.item icon="puzzle-piece" :href="route('consoles.index')" :current="request()->routeIs('consoles.*')" wire:navigate>
                    {{ __('Consoles') }}
                </flux:sidebar.item>
                <flux:sidebar.item icon="rectangle-stack" :href="route('games.index')" :current="request()->routeIs('games.*')" wire:navigate>
                    {{ __('Games') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>

            @php($installed = App\Models\ConsoleSourceFolder::consoles())

            @if ($installed->isNotEmpty())
                {{--
                    Geometry deliberately mirrors flux:sidebar.item above: no
                    wrapper inset, px-3 on the row, and a 16px icon box so the
                    console labels line up with the nav labels rather than
                    sitting 19px further right.
                --}}
                <p class="kicker px-3 pt-4 -pb-5 text-fg-faint">{{ __('Installed') }}</p>

                <div class="flex flex-col gap-px">
                    @foreach ($installed as $console)
                        <a
                            href="{{ route('consoles.games', ['console' => $console->key]) }}"
                            wire:navigate
                            @class([
                                'flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm transition-colors hover:bg-hover hover:text-fg',
                                'bg-hover text-fg' => request()->routeIs('consoles.games') && request()->route('console') === $console->key,
                                'text-fg-cool' => ! (request()->routeIs('consoles.games') && request()->route('console') === $console->key),
                            ])
                        >
                            <img src="{{ $console->icon }}" alt="" class="size-4 shrink-0 object-contain" />
                            <span class="truncate">{{ $console->name }}</span>
                            <span class="ms-auto font-mono text-xs text-fg-faint">{{ Illuminate\Support\Arr::get($gameCounts, $console->key, 0) }}</span>
                        </a>
                    @endforeach
                </div>
            @endif

            <flux:spacer />

            <div class="mt-auto border-t border-line pt-4">
                {{--
                    `kicker` goes on the label only. On the flex parent its
                    0.14em tracking also stretched the figure, which then wrapped.
                --}}
                <div class="flex items-baseline justify-between gap-2 text-fg-muted">
                    <span class="kicker">{{ __('Storage') }}</span>
                    <span class="font-mono text-xs whitespace-nowrap">{{ $storageUsed }} / {{ $storageTotal }}</span>
                </div>

                <div class="mt-2 mb-4 h-1 overflow-hidden rounded-sm bg-raised">
                    <div class="h-full rounded-sm bg-accent-deep transition-[width] duration-300" style="width: {{ $storagePercent }}%"></div>
                </div>

                <div class="flex items-center gap-2.5">
                    <span class="grid h-6.5 w-6.5 shrink-0 place-items-center rounded-md border border-line-input bg-raised font-mono text-xs text-fg-muted">
                        {{ Str::lower(Str::substr(auth()->user()->username ?? auth()->user()->name, 0, 2)) }}
                    </span>

                    <span class="min-w-0 flex-1 leading-tight">
                        <span class="block truncate text-sm text-fg-soft">{{ auth()->user()->username ?? auth()->user()->name }}</span>
                        <span class="block font-mono text-xs text-fg-faint">{{ config('app.version', 'v0.0.1') }}</span>
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

        @fluxScripts
    </body>
</html>
