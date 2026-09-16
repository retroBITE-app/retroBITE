@php
    use App\Enums\ShareProtocol;
    use App\Services\NetworkService;
    use App\Support\Console;

    // Placeholder library data. There is no Game model yet; these rows stand in
    // for it so the dashboard reads as designed. Every field maps 1:1 to what
    // the previous build passed in, so swapping in real records is a
    // substitution rather than a rewrite.
    $recent = [
        ['title' => 'Gran Turismo 3: A-Spec', 'console' => 'PlayStation 2', 'path' => 'games/ps2', 'size' => '4.1 GB', 'added' => '2 hours ago'],
        ['title' => 'The Wind Waker', 'console' => 'GameCube', 'path' => 'games/gc', 'size' => '1.3 GB', 'added' => 'yesterday'],
        ['title' => 'Super Metroid', 'console' => 'Super Nintendo', 'path' => 'games/snes', 'size' => '3.0 MB', 'added' => '3 days ago'],
    ];

    $hero = $recent[0];

    $cells = [
        ['label' => 'Games', 'value' => '20', 'sub' => 'across 10 consoles'],
        ['label' => 'Identified', 'value' => '14', 'sub' => '6 still unmatched'],
        ['label' => 'Consoles', 'value' => (string) Console::allInstalled()->count(), 'sub' => 'installed'],
        ['label' => 'Storage', 'value' => '412 GB', 'sub' => 'of 1.8 TB'],
    ];

    $unmatched = [
        ['file' => 'SLUS_203.12.iso', 'console' => 'ps2', 'size' => '3.8 GB', 'added' => '2 hours ago'],
        ['file' => 'unknown_disc_02.gcm', 'console' => 'gc', 'size' => '1.3 GB', 'added' => 'yesterday'],
        ['file' => 'rom_hack_final.sfc', 'console' => 'snes', 'size' => '2.0 MB', 'added' => '3 days ago'],
    ];

    // Real, not dummy: probes the share container over TCP.
    $status = app(NetworkService::class)->status();
    $hostIp = config('settings.network.host_ip');
    $shareUser = config('settings.network.username');
    $shares = Console::allInstalled();

    $hour = (int) now()->format('G');
    $greeting = $hour >= 17 ? __('Good evening') : ($hour >= 12 ? __('Good afternoon') : __('Good morning'));
@endphp

<x-layouts::app :title="__('Dashboard')">
    <div class="flex flex-col gap-6">
        <div class="-mb-2 flex items-center justify-between gap-4">
            <div class="min-w-0">
                <p class="kicker mb-1.5 text-fg-faint">{{ $greeting }}</p>
                <h1 class="text-display font-medium tracking-display text-fg-bright">{{ __('Your collection') }}</h1>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-[minmax(0,1.55fr)_minmax(0,1fr)]">
            {{-- Hero. No scraped artwork exists yet, so it renders the design's
                 no-backdrop state: the glyph on the sunken ground. --}}
            <div class="relative flex h-[300px] overflow-hidden rounded-xl border border-line-strong bg-sunken">
                <flux:icon.photo class="absolute top-1/2 right-8 size-10 -translate-y-1/2 text-line-bright" />

                <div class="hero-fade-x pointer-events-none absolute inset-0"></div>
                @if (config('settings.interface.scanlines'))
                    <div class="scanlines absolute inset-0"></div>
                @endif

                <div class="relative mt-auto w-full p-6">
                    <p class="kicker text-accent">{{ __('Recently added') }} · {{ $hero['console'] }}</p>

                    <h2 class="mt-2 truncate text-[28px] font-medium tracking-display text-fg-bright">{{ $hero['title'] }}</h2>

                    <p class="mt-1 truncate font-mono text-sm text-fg-muted">
                        {{ $hero['path'] }} · {{ $hero['size'] }} · {{ $hero['added'] }}
                    </p>

                    <div class="mt-4">
                        <a
                            href="{{ route('consoles.index') }}"
                            wire:navigate
                            class="inline-flex items-center gap-1.5 rounded-lg border border-accent-tint/60 px-3.5 py-2 text-sm text-accent transition-colors hover:bg-accent-tint/14 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
                        >
                            {{ __('View details') }}
                            <flux:icon.arrow-right variant="micro" />
                        </a>
                    </div>
                </div>
            </div>

            <div class="flex min-w-0 flex-col gap-2.5">
                @foreach ($recent as $game)
                    <a
                        href="{{ route('consoles.index') }}"
                        wire:navigate
                        class="relative flex min-w-0 flex-1 items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface p-2.5 transition-colors hover:border-line-input hover:bg-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
                    >
                        <span aria-hidden="true" class="relative grid h-18 w-13 shrink-0 place-items-center rounded-md border border-line-input bg-sunken">
                            <flux:icon.photo class="size-4.5 text-fg-faint" />
                        </span>

                        <span class="relative min-w-0 flex-1">
                            <span class="block truncate text-sm text-fg">{{ $game['title'] }}</span>
                            <span class="mt-1 block text-sm text-fg-dim">{{ $game['console'] }} · {{ __('added') }} {{ $game['added'] }}</span>
                            <span class="mt-1 block font-mono text-xs text-fg-faint">{{ $game['size'] }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        </div>

        <section>
            <dl class="grid grid-cols-2 overflow-hidden rounded-xl border border-line bg-sunken lg:grid-cols-4">
                @foreach ($cells as $index => $cell)
                    <div @class([
                        'border-line px-4.5 py-4',
                        'border-r' => $index % 2 === 0,
                        'border-b lg:border-b-0' => $index < 2,
                        'lg:border-r lg:last:border-r-0',
                    ])>
                        <dt class="kicker text-fg-faint">{{ $cell['label'] }}</dt>
                        <dd class="mt-2 text-[22px] font-medium tracking-display text-fg-bright">{{ $cell['value'] }}</dd>
                        <dd class="mt-1 text-sm text-fg-dim">{{ $cell['sub'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <section>
            <div class="mb-3.5 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2">
                <h2 class="text-lg font-medium text-fg-bright">{{ __('Needs identifying') }}</h2>
                <p class="text-sm text-fg-dim">
                    {{ count($unmatched) }} {{ __('files scanned but not matched to a title') }}
                </p>
            </div>

            <ul class="overflow-hidden rounded-xl border border-line bg-sunken">
                @foreach ($unmatched as $game)
                    <li class="flex flex-wrap items-center gap-x-5 gap-y-3 border-b border-line/70 px-4 py-3 transition-colors last:border-b-0 hover:bg-hover">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-mono text-sm text-fg-soft">{{ $game['file'] }}</span>
                            <span class="mt-1.5 flex items-center gap-2.5">
                                <span class="kicker rounded border border-line-strong px-1.5 py-0.5 text-accent-muted">{{ $game['console'] }}</span>
                                <span class="font-mono text-xs text-fg-faint">{{ $game['size'] }} · {{ $game['added'] }}</span>
                            </span>
                        </span>

                        <a
                            href="{{ route('consoles.index') }}"
                            wire:navigate
                            class="flex shrink-0 items-center gap-1.5 rounded-lg border border-accent-tint/50 px-2.5 py-1.5 text-sm text-accent transition-colors hover:bg-accent-tint/12 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
                        >
                            <flux:icon.sparkles variant="micro" />
                            {{ __('Identify') }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="space-y-4">
            <h2 class="text-lg font-medium text-fg-bright">{{ __('Network shares') }}</h2>

            <div class="grid gap-4 lg:grid-cols-2">
                @foreach (ShareProtocol::cases() as $protocol)
                    @php($online = $status[$protocol->value] ?? null)

                    <div class="flex flex-col overflow-hidden rounded-xl border border-line bg-sunken">
                        <div class="flex items-center justify-between border-b border-line/70 px-3.5 py-3">
                            <h3 class="text-sm text-fg-bright">{{ $protocol->label() }}</h3>

                            <span class="flex items-center gap-2">
                                <span @class([
                                    'h-2 w-2 shrink-0 rounded-full',
                                    'bg-accent shadow-glow' => $online,
                                    'bg-danger' => ! $online,
                                ])></span>
                                <span @class([
                                    'text-sm',
                                    'text-accent' => $online,
                                    'text-danger' => ! $online,
                                ])>{{ $online ? __('Online') : __('Offline') }}</span>
                            </span>
                        </div>

                        <div class="space-y-4 px-3.5 py-3.5">
                            <dl class="grid grid-cols-2 gap-4 text-sm">
                                <div>
                                    <dt class="text-fg-faint">{{ __('Host') }}</dt>
                                    <dd class="mt-0.5 font-mono text-sm text-fg-soft">{{ $hostIp }}</dd>
                                </div>
                                <div>
                                    <dt class="text-fg-faint">{{ __('Ports') }}</dt>
                                    <dd class="mt-0.5 font-mono text-sm text-fg-soft">{{ $protocol->displayPorts() }}</dd>
                                </div>
                                <div>
                                    <dt class="text-fg-faint">{{ __('Credentials') }}</dt>
                                    <dd class="mt-0.5 font-mono text-sm text-fg-soft">{{ $shareUser }} / ******</dd>
                                </div>
                            </dl>

                            <div>
                                <p class="kicker mb-2 text-fg-faint">{{ __('Shares') }}</p>

                                @if ($shares->isEmpty())
                                    <div class="rounded-xl border border-dashed border-line-input px-6 py-10 text-center">
                                        <p class="text-sm text-fg-soft">{{ __('No consoles installed yet.') }}</p>
                                    </div>
                                @else
                                    <ul class="space-y-1">
                                        @foreach ($shares as $share)
                                            <li class="flex items-center gap-2 rounded-md border border-line/70 bg-surface px-3 py-2">
                                                <img src="{{ $share->icon }}" alt="" class="h-4 w-4 shrink-0 object-contain" />
                                                <span class="min-w-0 flex-1 truncate text-sm text-fg-soft">{{ $share->name }}</span>
                                                <span class="min-w-0 shrink truncate font-mono text-xs text-fg-dim">{{ $protocol->connectionString($hostIp, $share->folder) }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    </div>
</x-layouts::app>
