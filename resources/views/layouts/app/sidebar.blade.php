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
                                @php($isCurrent = request()->routeIs('consoles.games') && request()->route('console') === $console->key)

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

                <flux:sidebar.item icon="rectangle-stack" :href="route('games.index')" :current="request()->routeIs('games.*')" wire:navigate>
                    {{ __('Games') }}
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

            <flux:spacer />

            <div class="mt-auto border-t border-line pt-4">
                {{-- What the providers will still answer, above the disk that
                     holds what they answered with. Its own component because it
                     polls: the rest of this layout is static until the next
                     navigation. --}}
                <livewire:api-status />

                {{-- What the workers are doing, between what the providers
                     will still answer and the disk it all lands on. --}}
                <livewire:system-activity />

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
