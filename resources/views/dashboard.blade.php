@php
    use App\Enums\GameStatus;
    use App\Enums\MediaKind;
    use App\Models\Game;
    use App\Models\ConsoleSourceFolder;
    use App\Models\RaProgress;
    use App\Models\RaUnlock;
    use App\Support\Console;
    use App\Support\LibraryStorage;
    use App\Support\Scanning\FolderCounts;
    use Illuminate\Support\Arr;
    use Illuminate\Support\Number;

    // Media rides along because both the hero and the cards behind it are key
    // art when there is any: one query for the lot rather than eight. Four,
    // because the newest is the hero and the three behind it are the cards.
    $recent = Game::query()
        ->with(['files', 'media'])
        ->latest('id')
        ->take(4)
        ->get();

    // One query for the four, rather than a progress lookup inside the map.
    $recentProgress = RaProgress::query()
        ->where('user_id', auth()->id())
        ->whereIn('ra_game_id', $recent->pluck('retroachievements_id')->filter())
        ->get()
        ->keyBy('ra_game_id');

    $recent = $recent
        ->map(function (Game $game) use ($recentProgress): array {
            $backdrop = $game->artwork(MediaKind::Backdrop);
            $progress = $recentProgress->get($game->retroachievements_id);

            return [
                'title' => $game->title,
                'console' => $game->console()?->name ?? $game->console,
                'path' => $game->console()?->libraryPath() ?? $game->console,
                'size' => Number::fileSize((int) $game->files->sum('size_bytes'), 1),
                'added' => $game->created_at?->diffForHumans() ?? '',
                'url' => route('games.show', $game->routeParameters()),
                'cover' => $game->artwork(MediaKind::Cover)?->url(App\Enums\ThumbnailSize::Grid),
                'backdrop' => $backdrop !== null ? route('media.show', ['path' => $backdrop->path]) : null,
                'achievements' => $progress !== null && $progress->achievements_possible > 0
                    ? ['unlocked' => $progress->unlocked_count, 'possible' => $progress->achievements_possible]
                    : null,
            ];
        })
        ->all();

    // The hero is left out of the cards, or the newest game shows up twice.
    $hero = Arr::first($recent);
    $others = array_slice($recent, 1);

    // The consoles somebody put in their library, not every directory that
    // happens to exist under the ROM root.
    $consoles = ConsoleSourceFolder::consoles();

    // Games are what the provider identified; files are what is on the disk.
    // The same two figures, in the same order, as the console cards — a
    // four-disc set is four files and one game, and a placeholder the
    // provider has not answered for yet is neither a game nor worth hiding.
    $games = Game::where('status', GameStatus::Matched)->count();
    $unmatched = Game::count() - $games;
    $files = $consoles->sum(function (Console $console): int {
        return FolderCounts::gamesIn($console);
    });

    // What the identified games take, against what is free, so the figure says
    // what room is left. Both are stored readings (see LibraryStorage): the
    // dashboard reads no disk.
    $storage = LibraryStorage::current();

    // One grouped row for the whole library. The counters are denormalised
    // onto ra_progress precisely so this is a sum and not an aggregation over
    // every unlock.
    $ra = RaProgress::query()
        ->where('user_id', auth()->id())
        ->selectRaw(
            'coalesce(sum(unlocked_count), 0) as unlocked,'
            .' coalesce(sum(unlocked_hardcore_count), 0) as unlocked_hardcore,'
            .' coalesce(sum(achievements_possible), 0) as possible,'
            .' coalesce(sum(points_earned), 0) as points,'
            .' coalesce(sum(points_hardcore_earned), 0) as points_hardcore'
        )
        ->first();

    $cells = [
        ['label' => __('Games'), 'value' => Number::format($games), 'sub' => __(':count still unmatched', ['count' => Number::format($unmatched)])],
        ['label' => __('Files'), 'value' => Number::format($files), 'sub' => trans_choice('across :count console|across :count consoles', $consoles->count(), ['count' => $consoles->count()])],
        ['label' => __('Consoles'), 'value' => (string) $consoles->count(), 'sub' => __('in your library')],
        ['label' => __('Achievements'), 'value' => Number::format((int) $ra->unlocked), 'sub' => __('of :count tracked', ['count' => Number::format((int) $ra->possible)])],
        ['label' => __('Points'), 'value' => Number::format((int) $ra->points), 'sub' => __('hardcore :count', ['count' => Number::format((int) $ra->points_hardcore)])],
        // Dashed rather than dropped when the mount cannot be read: this grid
        // is six cells over two and three columns, and removing one leaves a
        // hole in it.
        [
            'label' => __('Storage'),
            'value' => $storage === null ? __('—') : Number::fileSize($storage->used, 1),
            'sub' => $storage === null
                ? __('library folder unreadable')
                : __('of :total, :free free', [
                    'total' => Number::fileSize($storage->total(), 1),
                    'free' => Number::fileSize($storage->free, 1),
                ]),
        ],
    ];

    // The five most recent unlocks, and what they were worth this week.
    $unlockFeed = RaUnlock::query()
        ->where('user_id', auth()->id())
        ->whereNotNull('unlocked_at')
        ->with(['achievement:id,ra_game_id,title,points,badge_name', 'achievement.game:id,title'])
        ->orderByDesc('unlocked_at')
        ->take(5)
        ->get()
        ->filter(function (RaUnlock $unlock): bool {
            return $unlock->achievement !== null;
        })
        ->map(function (RaUnlock $unlock): array {
            return [
                'id' => $unlock->id,
                'title' => $unlock->achievement->title,
                'game' => $unlock->achievement->game?->title ?? '',
                'points' => $unlock->achievement->points,
                'badge' => $unlock->achievement->badgeUrl(),
                'when' => $unlock->unlocked_at?->diffForHumans(short: true) ?? '',
            ];
        })
        ->values();

    $pointsThisWeek = (int) RaUnlock::query()
        ->where('ra_unlocks.user_id', auth()->id())
        ->where('ra_unlocks.unlocked_at', '>=', now()->subWeek())
        ->join('ra_achievements', 'ra_achievements.id', '=', 'ra_unlocks.ra_achievement_id')
        ->sum('ra_achievements.points');

    // Files the provider could not name. Their game rows still carry the
    // filename as a stand-in title, which is what makes them recognisable here.
    $unmatched = Game::query()
        ->where('status', GameStatus::Unmatched)
        ->with(['files' => function ($query): void {
            $query->whereNull('missing_since');
        }])
        ->latest('id')
        ->take(5)
        ->get()
        ->map(function (Game $game): array {
            return [
                'file' => $game->files->first()?->filename ?? $game->title,
                'console' => $game->console,
                'size' => Number::fileSize((int) $game->files->sum('size_bytes'), 1),
                'added' => $game->created_at?->diffForHumans() ?? '',
                'url' => route('games.show', $game->routeParameters()),
            ];
        })
        ->all();

    // The hour where the library's owner is, per APP_TIMEZONE — the server's
    // own clock is UTC unless told otherwise, and a greeting two hours out
    // is the one line on the page anybody would notice.
    $hour = (int) now(config('app.timezone'))->format('G');
    $greeting = $hour >= 17 ? __('Good evening') : ($hour >= 12 ? __('Good afternoon') : __('Good morning'));
    $name = auth()->user()->username ?? auth()->user()->name;
@endphp

<x-layouts::app :title="__('Dashboard')">
    <div class="flex flex-col gap-6">
        <x-page-header
            :kicker="__(':greeting, :name', ['greeting' => $greeting, 'name' => $name])"
            :title="__('Your collection')"
            class="-mb-2!"
        />

        <div class="grid gap-4 lg:grid-cols-[minmax(0,1.55fr)_minmax(0,1fr)]">
            <div class="relative flex h-[300px] overflow-hidden rounded-xl border border-line-strong bg-sunken">
                @if (Arr::get($hero ?? [], 'backdrop') === null)
                    {{-- The design's no-backdrop state: the glyph on the sunken ground. --}}
                    <flux:icon.photo class="absolute top-1/2 right-8 size-10 -translate-y-1/2 text-line-bright" aria-hidden="true" />
                @endif

                {{-- Reads left-to-right, so the text side is darkened hardest. --}}
                <x-backdrop :src="Arr::get($hero ?? [], 'backdrop')" fade="hero-x" position="50% 20%" />

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

                        @if ($hero['achievements'] !== null)
                            <x-achievement-progress
                                :unlocked="Arr::get($hero, 'achievements.unlocked')"
                                :possible="Arr::get($hero, 'achievements.possible')"
                                class="mt-4 max-w-85"
                            />
                        @endif
                    @endif

                    <div class="mt-4">
                        {{-- The hero is a game, so this goes to that game. With
                             nothing scanned yet there is none to go to, and the
                             only useful next step is adding a console. --}}
                        <flux:button
                            variant="primary"
                            :href="$hero === null ? route('consoles.index') : $hero['url']"
                            icon:trailing="arrow-right"
                            wire:navigate
                        >
                            {{ $hero === null ? __('Add a console') : __('View details') }}
                        </flux:button>
                    </div>
                </div>
            </div>

            <div class="flex min-w-0 flex-col gap-2.5">
                @foreach ($others as $game)
                    <a
                        href="{{ $game['url'] }}"
                        wire:navigate
                        class="relative flex min-w-0 flex-1 items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface p-2.5 transition-colors hover:border-accent-tint/55 hover:bg-hover focus-ring"
                    >
                        {{-- The game's own key art, faded out before it reaches the
                             text. The mask covers the scanlines too, so they stop
                             where the art does. --}}
                        @if ($game['backdrop'] !== null)
                            <x-backdrop :src="$game['backdrop']" fade="art-l" position="center" />
                        @endif

                        <x-cover-frame :src="$game['cover']" size="poster" />

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
            {{-- Separated by a 1px gap over the border colour rather than by
                 per-cell border classes: the cell count changed once and the
                 index arithmetic behind those classes did not survive it. --}}
            <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line lg:grid-cols-3">
                @foreach ($cells as ['label' => $label, 'value' => $value, 'sub' => $sub])
                    <x-stat :label="$label" :sub="$sub" size="lg" class="bg-sunken px-4.5 py-4">{{ $value }}</x-stat>
                @endforeach
            </dl>
        </section>

        @if ($unlockFeed->isNotEmpty())
            <section>
                <x-section-header :title="__('Latest achievements')">
                    <x-slot:aside>
                        <p class="font-mono text-sm text-accent">
                            {{ __('+:points pts this week', ['points' => Number::format($pointsThisWeek)]) }}
                        </p>
                    </x-slot:aside>
                </x-section-header>

                <x-panel as="ul" tone="sunken" divided>
                    @foreach ($unlockFeed as $unlock)
                        <li class="flex items-center gap-3.25 px-3.5 py-2.75">
                            <div class="grid size-9.5 shrink-0 place-items-center overflow-hidden rounded-lg border border-accent/35 bg-accent-tint/10">
                                @if ($unlock['badge'] !== null)
                                    <img src="{{ $unlock['badge'] }}" alt="" loading="lazy" class="size-full object-cover" />
                                @else
                                    <flux:icon.trophy class="size-4 text-accent" />
                                @endif
                            </div>

                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm text-fg-bright">{{ $unlock['title'] }}</p>
                                <p class="mt-0.5 truncate text-xs text-fg-muted">{{ $unlock['game'] }}</p>
                            </div>

                            <div class="shrink-0 text-right">
                                <p class="font-mono text-xs text-accent">{{ $unlock['points'] }}</p>
                                <p class="mt-0.5 font-mono text-[10px] text-fg-dim">{{ $unlock['when'] }}</p>
                            </div>
                        </li>
                    @endforeach
                </x-panel>
            </section>
        @endif

        {{-- Only while something is left to identify: an empty list is a
             heading with nothing under it. --}}
        @if (count($unmatched) > 0)
            <section>
                <x-section-header :title="__('Needs identifying')">
                    <x-slot:aside>
                        <p class="text-sm text-fg-dim">
                            {{ count($unmatched) }} {{ __('files scanned but not matched to a title') }}
                        </p>
                    </x-slot:aside>
                </x-section-header>

                <x-panel as="ul" tone="sunken" divided>
                    @foreach ($unmatched as $game)
                        <li class="flex flex-wrap items-center gap-x-5 gap-y-3 px-4 py-3 transition-colors hover:bg-hover">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-mono text-sm text-fg-soft">{{ $game['file'] }}</span>
                                <span class="mt-1.5 flex items-center gap-2.5">
                                    <x-badge>{{ $game['console'] }}</x-badge>
                                    <span class="font-mono text-xs text-fg-faint">{{ $game['size'] }} · {{ $game['added'] }}</span>
                                </span>
                            </span>

                            <flux:button size="sm" variant="primary" icon="sparkles" :href="$game['url']" wire:navigate>
                                {{ __('Identify') }}
                            </flux:button>
                        </li>
                    @endforeach
                </x-panel>
            </section>
        @endif

        <livewire:network-shares />
    </div>
</x-layouts::app>
