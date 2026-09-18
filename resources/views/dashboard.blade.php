@php
    use App\Enums\GameStatus;
    use App\Enums\MediaKind;
    use App\Enums\ShareProtocol;
    use App\Models\Game;
    use App\Models\ConsoleSourceFolder;
    use App\Models\GameFile;
    use App\Services\NetworkService;
    use App\Support\Console;
    use Illuminate\Support\Number;

    // Media rides along because both the hero and the cards behind it are key
    // art when there is any: one query for the lot rather than six.
    $recent = Game::query()
        ->with(['files', 'media'])
        ->latest('id')
        ->take(3)
        ->get()
        ->map(fn (Game $game) => [
            'title' => $game->title,
            'console' => $game->console()?->name ?? $game->console,
            'path' => $game->console()?->libraryPath() ?? $game->console,
            'size' => Number::fileSize((int) $game->files->sum('size_bytes'), 1),
            'added' => $game->created_at?->diffForHumans() ?? '',
            'id' => $game->id,
            'cover' => ($cover = $game->artwork(MediaKind::Cover)) ? route('media.show', ['path' => $cover->path]) : null,
            'backdrop' => ($backdrop = $game->artwork(MediaKind::Backdrop)) ? route('media.show', ['path' => $backdrop->path]) : null,
        ])
        ->all();

    $hero = $recent[0] ?? null;

    // The consoles somebody put in their library, not every directory that
    // happens to exist under the ROM root.
    $consoles = ConsoleSourceFolder::consoles();

    $games = Game::count();
    $identified = Game::where('status', GameStatus::Matched)->count();
    $bytes = (int) GameFile::whereNull('missing_since')->sum('size_bytes');

    $cells = [
        ['label' => 'Games', 'value' => (string) $games, 'sub' => trans_choice('across :count console|across :count consoles', $consoles->count(), ['count' => $consoles->count()])],
        ['label' => 'Identified', 'value' => (string) $identified, 'sub' => __(':count still unmatched', ['count' => $games - $identified])],
        ['label' => 'Consoles', 'value' => (string) $consoles->count(), 'sub' => __('in your library')],
        ['label' => 'Storage', 'value' => Number::fileSize($bytes, 1), 'sub' => __('on disk')],
    ];

    // Files the provider could not name. Their game rows still carry the
    // filename as a stand-in title, which is what makes them recognisable here.
    $unmatched = Game::query()
        ->where('status', GameStatus::Unmatched)
        ->with(['files' => fn ($q) => $q->whereNull('missing_since')])
        ->latest('id')
        ->take(5)
        ->get()
        ->map(fn (Game $game) => [
            'file' => $game->files->first()?->filename ?? $game->title,
            'console' => $game->console,
            'size' => Number::fileSize((int) $game->files->sum('size_bytes'), 1),
            'added' => $game->created_at?->diffForHumans() ?? '',
            'id' => $game->id,
        ])
        ->all();

    // Real, not dummy: probes the share container over TCP.
    $status = app(NetworkService::class)->status();
    $hostIp = config('settings.network.host_ip');
    $shareUser = config('settings.network.username');
    $shares = $consoles;

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
            <div class="relative flex h-[300px] overflow-hidden rounded-xl border border-line-strong bg-sunken">
                @if ($hero !== null && $hero['backdrop'] !== null)
                    <div class="absolute inset-0 bg-cover bg-[position:50%_20%]"
                         style="background-image: url('{{ $hero['backdrop'] }}')"></div>
                @else
                    {{-- The design's no-backdrop state: the glyph on the sunken ground. --}}
                    <flux:icon.photo class="absolute top-1/2 right-8 size-10 -translate-y-1/2 text-line-bright" aria-hidden="true" />
                @endif

                {{-- Reads left-to-right, so the text side is darkened hardest. --}}
                <div class="hero-fade-x pointer-events-none absolute inset-0"></div>
                @scanlines
                    <div class="scanlines absolute inset-0"></div>
                @endscanlines

                <div class="relative mt-auto w-full p-6">
                    @if ($hero === null)
                        {{-- A library nobody has scanned yet. --}}
                        <p class="kicker text-accent">{{ __('Nothing here yet') }}</p>

                        <h2 class="mt-2 truncate text-[28px] font-medium tracking-display text-fg-bright">{{ __('Add a console') }}</h2>

                        <p class="mt-1 truncate font-mono text-sm text-fg-muted">
                            {{ __('Point it at your ROMs and scan') }}
                        </p>
                    @else
                        <p class="kicker text-accent">{{ __('Recently added') }} · {{ $hero['console'] }}</p>

                        <h2 class="mt-2 truncate text-[28px] font-medium tracking-display text-fg-bright">{{ $hero['title'] }}</h2>

                        <p class="mt-1 truncate font-mono text-sm text-fg-muted">
                            {{ $hero['path'] }} · {{ $hero['size'] }} · {{ $hero['added'] }}
                        </p>
                    @endif

                    <div class="mt-4">
                        {{-- The hero is a game, so this goes to that game. With
                             nothing scanned yet there is none to go to, and the
                             only useful next step is adding a console. --}}
                        <a
                            href="{{ $hero === null ? route('consoles.index') : route('games.show', $hero['id']) }}"
                            wire:navigate
                            class="inline-flex items-center gap-1.5 rounded-lg border border-accent-tint/60 px-3.5 py-2 text-sm text-accent transition-colors hover:bg-accent-tint/14 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
                        >
                            {{ $hero === null ? __('Add a console') : __('View details') }}
                            <flux:icon.arrow-right variant="micro" />
                        </a>
                    </div>
                </div>
            </div>

            <div class="flex min-w-0 flex-col gap-2.5">
                @foreach ($recent as $game)
                    <a
                        href="{{ route('games.show', $game['id']) }}"
                        wire:navigate
                        class="relative flex min-w-0 flex-1 items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface p-2.5 transition-colors hover:border-line-input hover:bg-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
                    >
                        {{-- The game's own key art, faded out before it reaches the
                             text. The mask covers the scanlines too, so they stop
                             where the art does. --}}
                        @if ($game['backdrop'] !== null)
                            <span aria-hidden="true" class="art-fade-l pointer-events-none absolute inset-0">
                                <span class="absolute inset-0 bg-cover bg-center"
                                      style="background-image: url('{{ $game['backdrop'] }}')"></span>
                                @scanlines
                                    <span class="scanlines absolute inset-0"></span>
                                @endscanlines
                            </span>
                        @endif

                        @if ($game['cover'] !== null)
                            <img src="{{ $game['cover'] }}" alt=""
                                 class="relative h-18 w-13 shrink-0 rounded-md border border-line-input object-cover" />
                        @else
                            <span aria-hidden="true" class="relative grid h-18 w-13 shrink-0 place-items-center rounded-md border border-line-input bg-sunken">
                                <flux:icon.photo class="size-4.5 text-fg-faint" />
                            </span>
                        @endif

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
                            href="{{ route('games.show', $game['id']) }}"
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
