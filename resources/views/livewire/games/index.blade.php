<?php

use App\Enums\GameStatus;
use App\Enums\MediaKind;
use App\Jobs\MatchGame;
use App\Jobs\ScrapeGameMedia;
use App\Models\AppSetting;
use App\Models\Game;
use App\Support\Console;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Games')] class extends Component
{
    use WithPagination;

    /** The two ways the library lists games. */
    private const VIEWS = ['cards', 'table'];

    /**
     * The console this page is fixed to, or '' for the whole library.
     *
     * Separate from the $console filter below so /ps2/games never renders as
     * /ps2/games?console=ps2, and locked because it is the route, not a control.
     */
    #[Locked]
    public string $lockedConsole = '';

    #[Url(as: 'q', except: '')]
    public string $query = '';

    /**
     * Named apart from the route's {console}: Livewire fills any public
     * property whose name matches a route parameter, and this one filling
     * would put ?console=gc back on /gc/games.
     */
    #[Url(as: 'console', except: '')]
    public string $consoleFilter = '';

    /** '' | placeholder | matched | unmatched */
    #[Url(as: 'status', except: '')]
    public string $status = '';

    /** One genre out of the provider's list, or '' for all of them. */
    #[Url(as: 'genre', except: '')]
    public string $genre = '';

    /**
     * '' | cards | table — empty meaning whatever the setting says.
     *
     * Empty by default so the choice stays out of the URL until somebody makes
     * one here, while ?view=table still shares a link in the other mode.
     */
    #[Url(as: 'view', except: '')]
    public string $view = '';

    /** The route's console, when this is one console's shelf rather than the library. */
    public function mount(?string $console = null): void
    {
        if ($console === null) {
            return;
        }

        abort_unless(Console::exists($console), 404);

        $this->lockedConsole = $console;
    }

    public function updated(string $property): void
    {
        // Any change to a filter invalidates the page you were on.
        if (in_array($property, ['query', 'consoleFilter', 'status', 'genre'], true)) {
            $this->resetPage();
        }
    }

    /** The console being listed, whichever supplied it. */
    #[Computed]
    public function consoleKey(): string
    {
        return $this->lockedConsole !== '' ? $this->lockedConsole : $this->consoleFilter;
    }

    /** The console this page is fixed to, or null for the whole library. */
    #[Computed]
    public function lockedTo(): ?Console
    {
        return Console::tryFrom($this->lockedConsole);
    }

    /** How to list the games: this visit's choice, else the remembered one. */
    #[Computed]
    public function viewMode(): string
    {
        return $this->view !== '' ? $this->view : (string) AppSetting::get(AppSetting::UI_GAMES_VIEW);
    }

    /** Switch layouts, and remember it for every other shelf too. */
    public function setView(string $mode): void
    {
        if (! in_array($mode, self::VIEWS, true)) {
            return;
        }

        $this->view = $mode;

        // The computed is memoised for the request, and this render is the
        // same request that just changed it.
        unset($this->viewMode);

        AppSetting::put(AppSetting::UI_GAMES_VIEW, $mode);
    }

    /** @return LengthAwarePaginator<int, Game> */
    #[Computed]
    public function games(): LengthAwarePaginator
    {
        // Never null in practice — the route is behind auth — but a null
        // binding compiles to `= NULL`, which matches nothing without saying so.
        $userId = auth()->id() ?? 0;

        return Game::query()
            // Before withSum/withCount, and that order is load-bearing: those
            // append a subselect only while no columns have been chosen, and a
            // select() after them would wipe what they added.
            ->select(
                'games.*',
                'ra_progress.unlocked_count as ra_unlocked',
                'ra_progress.unlocked_hardcore_count as ra_unlocked_hardcore',
                'ra_progress.achievements_possible as ra_achievements_possible',
                'ra_progress.points_earned as ra_points',
                'ra_progress.points_hardcore_earned as ra_points_hardcore',
                'ra_progress.points_possible as ra_points_possible',
            )
            // One join and no aggregation per row, which is the whole reason
            // achievements_possible is denormalised onto ra_progress. Safe for
            // the paginator's count because ra_progress is unique on
            // (user_id, ra_game_id), so it cannot multiply rows.
            ->leftJoin('ra_progress', fn ($join) => $join
                ->on('ra_progress.ra_game_id', '=', 'games.retroachievements_id')
                ->where('ra_progress.user_id', '=', $userId))
            // Qualified from here down, because the join makes a bare column
            // name one added column away from being ambiguous at runtime.
            ->when($this->query !== '', fn ($q) => $q->where('games.title', 'like', '%'.$this->query.'%'))
            ->when($this->consoleKey !== '', fn ($q) => $q->forConsole($this->consoleKey))
            ->when($this->status !== '', fn ($q) => $q->where('games.status', $this->status))
            // Matched as one of the comma-separated parts rather than with a
            // LIKE, or picking "Action" would also pull in every "Action /
            // Adventure" the provider spells as its own genre.
            ->when($this->genre !== '', fn ($q) => $q->whereRaw(
                "FIND_IN_SET(?, REPLACE(REPLACE(games.genre, ' ,', ','), ', ', ',')) > 0",
                [$this->genre],
            ))
            // Both for the cards: the cover comes out of the media relation in
            // memory, and the size is a sum rather than every file loaded.
            ->with(['media' => fn ($q) => $q->ofKind(MediaKind::Cover)])
            ->withSum('files as size_bytes_sum', 'size_bytes')
            ->withCount('files')
            // Read by blockedFromLookup(), which otherwise runs a files query
            // per row — twenty-four extra selects on a page of placeholders.
            ->withCount(['files as identifiable_files_count' => fn ($q) => $q->identifiable()->present()])
            ->orderBy('games.title')
            ->paginate(24);
    }

    /**
     * Only consoles that actually hold something, so the filter never offers
     * an empty result.
     *
     * @return Collection<int, Console>
     */
    #[Computed]
    public function consoles(): Collection
    {
        return Game::query()->distinct()->orderBy('console')->pluck('console')
            ->map(fn (string $key) => Console::tryFrom($key))
            ->filter()
            ->values();
    }

    /**
     * Every genre the library actually holds, one entry each.
     *
     * The provider hands them over comma-separated in a single column — "Music,
     * Music and Dancing" is one game — so the list is what you get by splitting
     * on that, which is also how the filter matches.
     *
     * @return Collection<int, string>
     */
    #[Computed]
    public function genres(): Collection
    {
        return Game::query()
            // Scoped to the console being listed, or a console's shelf would
            // offer genres nothing on it has.
            ->when($this->consoleKey !== '', fn ($q) => $q->forConsole($this->consoleKey))
            ->whereNotNull('genre')
            ->where('genre', '<>', '')
            ->distinct()
            ->pluck('genre')
            ->flatMap(fn (string $genre) => explode(',', $genre))
            ->map(fn (string $genre) => trim($genre))
            ->filter()
            ->unique()
            ->sort()
            ->values();
    }

    /**
     * Put one game to the provider now.
     *
     * Queued rather than run here: the scraper queue paces its requests, and a
     * page should not sit waiting on somebody else's server.
     */
    public function identify(int $id): void
    {
        $game = Game::find($id);

        if ($game === null) {
            return;
        }

        if ($reason = $game->blockedFromLookup()) {
            Flux::toast(variant: 'warning', text: $reason);

            return;
        }

        MatchGame::dispatch($game->id);

        Flux::toast(text: __('Identifying :title.', ['title' => $game->title]));
    }

    /** Fetch artwork for one game now. */
    public function fetchMedia(int $id): void
    {
        $game = Game::find($id);

        if ($game === null) {
            return;
        }

        if ($reason = $game->blockedFromMediaScrape()) {
            Flux::toast(variant: 'warning', text: $reason);

            return;
        }

        ScrapeGameMedia::dispatch($game->id);

        Flux::toast(text: __('Fetching artwork for :title.', ['title' => $game->title]));
    }

    public function clear(): void
    {
        $this->reset('query', 'consoleFilter', 'status', 'genre');
        $this->resetPage();
    }
}; ?>

<section class="w-full">
    <div class="flex flex-col gap-6">
        @if ($this->lockedTo !== null)
            <div class="min-w-0">
                <a
                    href="{{ route('consoles.index') }}"
                    wire:navigate
                    class="kicker mb-1.5 inline-flex items-center gap-1.5 text-fg-faint transition-colors hover:text-accent"
                >
                    <flux:icon.arrow-left variant="micro" />
                    {{ __('Consoles') }}
                </a>
                <div class="flex items-center gap-3">
                    <img src="{{ $this->lockedTo->icon }}" alt="" class="size-9 shrink-0 object-contain" />
                    <h1 class="text-display font-medium tracking-display text-fg-bright">{{ $this->lockedTo->name }}</h1>
                </div>
            </div>
        @else
            <div class="min-w-0">
                <p class="kicker mb-1.5 text-fg-faint">{{ __('Library') }}</p>
                <h1 class="text-display font-medium tracking-display text-fg-bright">{{ __('Games') }}</h1>
            </div>
        @endif

        <div class="flex flex-wrap items-end gap-3">
            <flux:input wire:model.live.debounce.300ms="query" :placeholder="__('Search titles')" class="min-w-56 flex-1" size="sm" />

            @if ($this->lockedTo === null)
                <flux:select wire:model.live="consoleFilter" size="sm" class="w-44">
                    <flux:select.option value="">{{ __('All consoles') }}</flux:select.option>
                    @foreach ($this->consoles as $option)
                        <flux:select.option value="{{ $option->key }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:select wire:model.live="genre" size="sm" class="w-44">
                <flux:select.option value="">{{ __('Any genre') }}</flux:select.option>
                @foreach ($this->genres as $option)
                    <flux:select.option value="{{ $option }}">{{ $option }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="status" size="sm" class="w-44">
                <flux:select.option value="">{{ __('Any status') }}</flux:select.option>
                @foreach (GameStatus::cases() as $case)
                    <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($query !== '' || $consoleFilter !== '' || $status !== '' || $genre !== '')
                <flux:button size="sm" variant="ghost" wire:click="clear">{{ __('Clear') }}</flux:button>
            @endif

            {{-- Pushed to the end of the row: it is not a filter, and on a
                 narrow screen it should wrap away from them rather than
                 between two selects. --}}
            <flux:button.group class="ms-auto">
                <flux:button
                    size="sm"
                    icon="squares-2x2"
                    :variant="$this->viewMode === 'cards' ? 'filled' : 'ghost'"
                    :aria-pressed="$this->viewMode === 'cards' ? 'true' : 'false'"
                    :aria-label="__('Show covers')"
                    wire:click="setView('cards')"
                />
                <flux:button
                    size="sm"
                    icon="list-bullet"
                    :variant="$this->viewMode === 'table' ? 'filled' : 'ghost'"
                    :aria-pressed="$this->viewMode === 'table' ? 'true' : 'false'"
                    :aria-label="__('Show a list')"
                    wire:click="setView('table')"
                />
            </flux:button.group>
        </div>

        @if ($this->games->isEmpty())
            <div class="rounded-xl border border-dashed border-line-input px-6 py-10 text-center">
                <p class="text-sm text-fg-soft">{{ __('Nothing matches that.') }}</p>
                <p class="mt-1 text-sm text-fg-faint">{{ __('Scan a console to fill the library.') }}</p>
            </div>
        @elseif ($this->viewMode === 'cards')
            {{-- Wrapped rather than a grid: each console sets its own cover
                 width, so a fixed column count would leave a SNES shelf in
                 columns sized for a PS2 one. --}}
            <ul class="flex flex-wrap items-start gap-4">
                @foreach ($this->games as $game)
                    <li wire:key="card-{{ $game->id }}">
                        <x-game-card :game="$game" :show-console="$this->lockedTo === null">
                            <x-slot:actions>
                                @if ($game->canBeIdentified())
                                    <flux:button size="xs" variant="filled" icon="sparkles"
                                                 :aria-label="__('Identify')"
                                                 :tooltip="__('Identify')"
                                                 wire:click="identify({{ $game->id }})"
                                                 wire:loading.attr="disabled"
                                                 wire:target="identify({{ $game->id }})" />
                                @elseif ($game->canFetchMedia())
                                    <flux:button size="xs" variant="filled" icon="photo"
                                                 :aria-label="__('Artwork')"
                                                 :tooltip="__('Artwork')"
                                                 wire:click="fetchMedia({{ $game->id }})"
                                                 wire:loading.attr="disabled"
                                                 wire:target="fetchMedia({{ $game->id }})" />
                                @endif
                            </x-slot:actions>
                        </x-game-card>
                    </li>
                @endforeach
            </ul>
        @else
            <div class="overflow-hidden rounded-xl border border-line">
                <table class="w-full text-sm">
                    <thead class="bg-sunken text-left text-fg-faint">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">{{ __('Title') }}</th>
                            @if ($this->lockedTo === null)
                                <th class="px-4 py-2.5 font-medium">{{ __('Console') }}</th>
                            @endif
                            <th class="px-4 py-2.5 font-medium">{{ __('Genre') }}</th>
                            <th class="px-4 py-2.5 font-medium">{{ __('Files') }}</th>
                            <th class="px-4 py-2.5 font-medium">{{ __('Status') }}</th>
                            <th class="px-4 py-2.5"><span class="sr-only">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->games as $game)
                            <tr wire:key="game-{{ $game->id }}" class="border-t border-line hover:bg-hover">
                                <td class="px-4 py-2.5">
                                    <a href="{{ route('games.show', $game) }}" wire:navigate class="font-medium text-fg-bright hover:text-accent">
                                        {{ $game->title }}
                                    </a>
                                </td>
                                @if ($this->lockedTo === null)
                                    <td class="px-4 py-2.5 text-fg-soft">{{ $game->console()?->name ?? $game->console }}</td>
                                @endif
                                {{-- The provider's whole list on hover, one line in the row:
                                     "Adventure / RealTime 3D, Adventure" is a single game,
                                     and it would set the column width for every other. --}}
                                <td class="max-w-44 truncate px-4 py-2.5 text-fg-soft" title="{{ $game->genre }}">
                                    {{ $game->genre ?: '—' }}
                                </td>
                                <td class="px-4 py-2.5 text-fg-soft">{{ $game->files_count }}</td>
                                <td class="px-4 py-2.5">
                                    <flux:badge size="sm" :color="match ($game->status) {
                                        App\Enums\GameStatus::Matched => 'green',
                                        App\Enums\GameStatus::Unmatched => 'amber',
                                        default => 'zinc',
                                    }">{{ $game->status->label() }}</flux:badge>
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    @if ($game->canBeIdentified())
                                        <flux:button size="xs" variant="ghost" icon="sparkles"
                                                     wire:click="identify({{ $game->id }})"
                                                     wire:loading.attr="disabled"
                                                     wire:target="identify({{ $game->id }})">
                                            {{ __('Identify') }}
                                        </flux:button>
                                    @elseif ($game->canFetchMedia())
                                        <flux:button size="xs" variant="ghost" icon="photo"
                                                     wire:click="fetchMedia({{ $game->id }})"
                                                     wire:loading.attr="disabled"
                                                     wire:target="fetchMedia({{ $game->id }})">
                                            {{ __('Artwork') }}
                                        </flux:button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Outside the branch: the cards were the only view without it, so
             page two of a shelf could only be reached by typing ?page=2.

             Flux's, not Laravel's: the stock pagination view is painted from
             the gray ramp, and only zinc is remapped onto the warm grounds,
             so it came out cold blue beside everything else. --}}
        @if ($this->games->isNotEmpty())
            <flux:pagination :paginator="$this->games" />
        @endif
    </div>
</section>
