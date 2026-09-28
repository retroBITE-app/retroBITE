<?php

use App\Enums\GameStatus;
use App\Enums\MediaKind;
use App\Jobs\MatchGame;
use App\Jobs\RateGame;
use App\Jobs\ScanConsoleFolder;
use App\Jobs\ScrapeGameMedia;
use App\Jobs\WriteConsoleExports;
use App\Models\AppSetting;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\Media;
use App\Support\Console;
use App\Support\MediaTypes;
use App\Support\Scanning\FolderCounts;
use App\Tools\ConsoleTools;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Games')] #[Layout('layouts::app', ['bleed' => true])] class extends Component
{
    use WithPagination;

    /** The two ways the library lists games. */
    private const VIEWS = ['cards', 'table'];

    /** How many games' key art a shelf's backdrop rotates through. */
    private const BACKDROPS = 6;

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

    /** One genre out of the provider's list, or '' for all of them. */
    #[Url(as: 'genre', except: '')]
    public string $genre = '';

    /**
     * One player count as the provider spells it, or '' for all of them.
     *
     * Matched whole rather than as a number: the field is a range as often as
     * it is a count — "1-2" and "1-4" are both ordinary answers — and there is
     * no arithmetic that turns those into a single figure without inventing
     * one. The list is built from what is actually on the shelf.
     */
    #[Url(as: 'players', except: '')]
    public string $players = '';

    /** The lowest provider rating to list, out of a hundred, or '' for all. */
    #[Url(as: 'rating', except: '')]
    public string $minRating = '';

    /**
     * 'needed' | 'identified', or '' for every game.
     *
     * Needed takes in both a game never looked up and one the provider had no
     * match for: either way it is waiting on somebody, and the list of them is
     * what the filter is for.
     */
    #[Url(as: 'identified', except: '')]
    public string $identified = '';

    /**
     * 'title' | 'rating' | 'year' | 'newest'.
     *
     * A view rather than a filter: Clear leaves it alone, because somebody who
     * asked for the best games first meant it about the next search too.
     */
    #[Url(as: 'sort', except: 'title')]
    public string $sort = 'title';

    /**
     * '' | cards | table — empty meaning whatever the setting says.
     *
     * Empty by default so the choice stays out of the URL until somebody makes
     * one here, while ?view=table still shares a link in the other mode.
     */
    #[Url(as: 'view', except: '')]
    public string $view = '';

    /**
     * Key art behind the shelf's hero: one of the best-rated games' art on
     * this console, picked afresh on each visit, or null for none yet.
     *
     * Picked in mount and kept rather than computed: a computed pick would be
     * drawn again on every filter change, and a backdrop that jumps under the
     * hand changing a select reads as a page failing to load the same one twice.
     */
    #[Locked]
    public ?string $heroArt = null;

    /** The route's console, when this is one console's shelf rather than the library. */
    public function mount(?string $console = null): void
    {
        if ($console === null) {
            return;
        }

        abort_unless(Console::exists($console), 404);

        $this->lockedConsole = $console;

        $pool = $this->backdropPool($this->lockedTo);
        $this->heroArt = $pool === [] ? null : Arr::random($pool);
    }

    public function updated(string $property): void
    {
        // Any change to a filter invalidates the page you were on.
        if (in_array($property, ['query', 'consoleFilter', 'genre', 'players', 'minRating', 'identified', 'sort'], true)) {
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

    /**
     * How this console's folder is read, for the figures beside the title.
     *
     * Shown on every console's shelf, the only arrangement it knows included:
     * it says how the folder is read whether or not there was a choice. Null
     * for the whole library, which is read every way at once.
     */
    #[Computed]
    public function layoutLabel(): ?string
    {
        $console = $this->lockedTo;

        if ($console === null) {
            return null;
        }

        return ConsoleSourceFolder::layoutFor($console)->label();
    }

    /**
     * The key art a shelf's backdrop rotates through: the best-rated games'
     * wallpaper on this console, one each, best first.
     *
     * There is no artwork shipped per console — config carries a logo and a
     * cartridge icon and nothing else — so the background is borrowed from the
     * games on the shelf. The wallpaper scope is what keeps it from being a
     * blown-up thumbnail or an in-game screenshot. Cached for an hour so a
     * visit costs no query, and newly scraped art joins without being told to;
     * the images themselves the browser keeps for good.
     *
     * @return array<int, string> media paths
     */
    private function backdropPool(Console $console): array
    {
        return Cache::remember('shelf.backdrops.'.$console->key, now()->addHour(), function () use ($console): array {
            return Media::query()
                ->join('games', 'games.id', '=', 'media.game_id')
                ->where('games.console', $console->key)
                ->wallpaper()
                // NULL first, or the unrated would head the list on MariaDB —
                // the same trap the rating sort works around.
                ->orderByRaw('games.rating IS NULL, games.rating DESC')
                ->orderBy('games.id')
                // The game's first wallpaper stands for it, so which one is
                // not left to whatever order the database returns.
                ->orderBy('media.id')
                // A game can hold several wallpapers; enough rows that the
                // first of each still reaches six games.
                ->limit(self::BACKDROPS * 5)
                ->get(['media.game_id', 'media.path'])
                ->unique('game_id')
                ->take(self::BACKDROPS)
                ->pluck('path')
                ->values()
                ->all();
        });
    }

    /**
     * What the hero says about the console, beside its name.
     *
     * One query for a header, of aggregates rather than rows: the shelf below
     * already pages through the games themselves.
     *
     * @return array{games: int, identified: int, size: string, unlocked: int, possible: int}|null
     */
    #[Computed]
    public function consoleStats(): ?array
    {
        $console = $this->lockedTo;

        if ($console === null) {
            return null;
        }

        // Two derived tables in one round trip, rather than one join: the files
        // and the progress rows would multiply each other and inflate both
        // sums. count(distinct) inside the first because the file join still
        // multiplies a game by its tracks.
        $totals = DB::selectOne(
            'select files.identified, files.bytes, progress.unlocked, progress.unlocked_hardcore, progress.possible'
            .' from (select count(distinct case when games.status = ? then games.id end) as identified,'
            .'   coalesce(sum(game_files.size_bytes), 0) as bytes'
            .'   from games left join game_files on game_files.game_id = games.id where games.console = ?) as files'
            .' cross join (select coalesce(sum(ra_progress.unlocked_count), 0) as unlocked,'
            .'   coalesce(sum(ra_progress.unlocked_hardcore_count), 0) as unlocked_hardcore,'
            .'   coalesce(sum(ra_progress.achievements_possible), 0) as possible'
            .'   from games join ra_progress on ra_progress.ra_game_id = games.retroachievements_id and ra_progress.user_id = ?'
            .'   where games.console = ?) as progress',
            [GameStatus::Matched->value, $console->key, auth()->id() ?? 0, $console->key],
        );

        $hardcore = AppSetting::enabled(AppSetting::RA_HARDCORE_PRIMARY);

        // Games are the identified ones, as on the console cards and the
        // dashboard: a file the provider could not name is a file, not yet a
        // game. Files are the stored count the cards use (MeasureLibrary).
        return [
            'games' => (int) ($totals->identified ?? 0),
            'identified' => (int) ($totals->identified ?? 0),
            'files' => FolderCounts::gamesIn($console),
            'size' => Number::fileSize((int) ($totals->bytes ?? 0), 1),
            'unlocked' => (int) (($hardcore ? $totals?->unlocked_hardcore : $totals?->unlocked) ?? 0),
            'possible' => (int) ($totals->possible ?? 0),
        ];
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
            // Matched as one of the comma-separated parts rather than with a
            // LIKE, or picking "Action" would also pull in every "Action /
            // Adventure" the provider spells as its own genre.
            ->when($this->genre !== '', fn ($q) => $q->whereRaw(
                "FIND_IN_SET(?, REPLACE(REPLACE(games.genre, ' ,', ','), ', ', ',')) > 0",
                [$this->genre],
            ))
            ->when($this->players !== '', fn ($q) => $q->where('games.players', $this->players))
            ->when($this->minRating !== '', fn ($q) => $q->where('games.rating', '>=', (int) $this->minRating))
            ->when($this->identified !== '', function ($q) {
                return $this->identified === 'identified'
                    ? $q->where('games.status', GameStatus::Matched)
                    : $q->where('games.status', '<>', GameStatus::Matched);
            })
            // Both for the cards: the cover comes out of the media relation in
            // memory, and the size is a sum rather than every file loaded.
            ->with(['media' => fn ($q) => $q->ofKind(MediaKind::Cover)])
            ->withSum('files as size_bytes_sum', 'size_bytes')
            ->withCount('files')
            // Read by blockedFromLookup(), which otherwise runs a files query
            // per row — twenty-four extra selects on a page of placeholders.
            ->withCount(['files as identifiable_files_count' => fn ($q) => $q->identifiable()->present()])
            ->tap(fn ($q) => match ($this->sort) {
                // `rating IS NULL` first puts the unrated last rather than
                // ahead of everything, which is what DESC alone does on
                // MariaDB. Title breaks the ties, so a page of games that all
                // scored 80 is still in an order somebody can read.
                'rating' => $q->orderByRaw('games.rating IS NULL, games.rating DESC')->orderBy('games.title'),
                // The provider sends either a bare year or an ISO date, so the
                // string sorts chronologically as it stands. Empty counts with
                // null: an unmatched game has '' rather than nothing at all,
                // and both mean the same thing here.
                'year' => $q->orderByRaw("games.release_date IS NULL OR games.release_date = '', games.release_date DESC")->orderBy('games.title'),
                'newest' => $q->orderByDesc('games.created_at')->orderBy('games.title'),
                default => $q->orderBy('games.title'),
            })
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
     * Every distinct genre and player count on the shelf, in one query.
     *
     * Both filter lists come from here rather than a distinct query each.
     * Scoped to the console being listed, or a shelf would offer values
     * nothing on it has.
     *
     * @return Collection<int, Game>
     */
    #[Computed]
    public function filterValues(): Collection
    {
        return Game::query()
            ->when($this->consoleKey !== '', fn ($q) => $q->forConsole($this->consoleKey))
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereNotNull('genre')->where('genre', '<>', ''))
                ->orWhere(fn ($q) => $q->whereNotNull('players')->where('players', '<>', '')))
            ->distinct()
            ->get(['genre', 'players']);
    }

    /**
     * Every player count on the shelf, as the provider spells them.
     *
     * Sorted naturally rather than alphabetically, so 2 comes before 10 and
     * "1-2" before "1-4". A straight sort puts "10" between "1" and "2".
     *
     * @return Collection<int, string>
     */
    #[Computed]
    public function playerCounts(): Collection
    {
        return $this->filterValues
            ->pluck('players')
            ->filter(fn (mixed $players): bool => is_string($players) && $players !== '')
            ->map(fn (string $players) => trim($players))
            ->filter()
            ->unique()
            ->sort(SORT_NATURAL)
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
        return $this->filterValues
            ->pluck('genre')
            ->filter(fn (mixed $genre): bool => is_string($genre) && $genre !== '')
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

    /**
     * Fetch the provider's rating for one game now.
     *
     * Forced, like the one on the game's own page: the row's menu is somebody
     * asking on purpose, and the job's unforced guard would drop a game that
     * already has a rating — which is the one they most likely meant.
     */
    public function fetchRating(int $id): void
    {
        $game = Game::find($id);

        if ($game === null) {
            return;
        }

        if ($reason = $game->blockedFromRating()) {
            Flux::toast(variant: 'warning', text: $reason);

            return;
        }

        RateGame::dispatch($game->id, force: true);

        Flux::toast(text: __('Fetching the rating for :title.', ['title' => $game->title]));
    }

    /*
     * The shelf's own actions, which are the console's rather than a game's.
     *
     * Every one of them reads $this->lockedTo instead of taking a console key.
     * The page is already fixed to one console by its route; a key in the call
     * would be a second answer to the same question, and the one the browser
     * could argue with.
     */

    /**
     * Whether the shelf offers ROM uploads.
     *
     * Behind the UI setting, and only for a console that is in the library:
     * one that is not has no folder for an upload to land in.
     */
    #[Computed]
    public function canUpload(): bool
    {
        $console = $this->lockedTo;

        return $console !== null
            && AppSetting::enabled(AppSetting::UI_UPLOADS)
            && ConsoleSourceFolder::has($console);
    }

    /** Whether the toolbox can file this console's loose games into folders of their own. */
    #[Computed]
    public function canOrganize(): bool
    {
        return $this->consoleTools?->canOrganize() ?? false;
    }

    /** Whether the toolbox can rename this console's files to or from its loader's form. */
    #[Computed]
    public function canRename(): bool
    {
        $console = $this->lockedTo;

        return $console !== null && (ConsoleTools::for($console)?->canRename() ?? false);
    }

    /** This console's toolbox, which names its own exports, or null for none. */
    #[Computed]
    public function consoleTools(): ?ConsoleTools
    {
        return $this->lockedTo === null ? null : ConsoleTools::for($this->lockedTo);
    }

    /**
     * The loader files this console's toolbox can write, if any.
     *
     * @return string[]
     */
    #[Computed]
    public function consoleExports(): array
    {
        $console = $this->lockedTo;

        if ($console === null) {
            return [];
        }

        $tools = ConsoleTools::for($console);

        return $tools !== null && $tools->canExport() ? $tools->exports() : [];
    }

    /** Queue a scan. Never runs here: a large library takes minutes to walk. */
    public function scanConsole(): void
    {
        $console = $this->lockedTo;

        if ($console === null) {
            return;
        }

        ScanConsoleFolder::dispatch($console->key);

        Flux::toast(text: __('Scanning :console. The library fills in as it goes.', ['console' => $console->name]));
    }

    /**
     * Queue artwork for every game on this console.
     *
     * $held is the difference between filling the gaps and starting over, the
     * same as it is on the console list.
     */
    public function fetchConsoleMedia(bool $held = false): void
    {
        $console = $this->lockedTo;

        if ($console === null) {
            return;
        }

        // Said once here rather than discovered one job at a time: with nothing
        // switched on every job returns having done nothing, and the page looks
        // broken rather than misconfigured.
        if (MediaTypes::enabled() === []) {
            Flux::toast(variant: 'warning', text: __('No media types are switched on. Choose some in Settings → Media.'));

            return;
        }

        $queued = ScrapeGameMedia::queueForConsole($console->key, held: $held);

        Flux::toast(text: $queued === 0
            ? __('Nothing to fetch — every identified game on :console already has the artwork switched on in Settings → Media.', ['console' => $console->name])
            : trans_choice(
                '{1} Fetching artwork for one game.|[2,*] Fetching artwork for :count games. The library fills in as it goes.',
                $queued,
                ['count' => $queued],
            ));
    }

    /** Queue a rating fetch for every identified game on this console. */
    public function fetchConsoleRatings(bool $held = false): void
    {
        $console = $this->lockedTo;

        if ($console === null) {
            return;
        }

        $queued = RateGame::queueForConsole($console->key, held: $held);

        Flux::toast(text: $queued === 0
            ? __('Nothing to fetch — every identified game on :console already has a rating.', ['console' => $console->name])
            : trans_choice(
                '{1} Fetching the rating for one game.|[2,*] Fetching ratings for :count games.',
                $queued,
                ['count' => $queued],
            ));
    }

    /**
     * Write a loader's own files back into this console's folder.
     *
     * The only thing on this page that writes to somebody's library, so it is
     * confirmed at the call site and never triggered by anything else.
     */
    public function writeConsoleExport(string $export): void
    {
        $console = $this->lockedTo;

        if ($console === null) {
            return;
        }

        if (! in_array($export, $this->consoleExports, true)) {
            return;
        }

        WriteConsoleExports::dispatch($console->key, $export);

        Flux::toast(text: __('Writing :console\'s :export files. Nothing else in the folder is touched.', [
            'console' => $console->name,
            'export' => Str::upper($export),
        ]));
    }

    public function clear(): void
    {
        // Not $sort: it says how to read the library rather than which part of
        // it to show, and clearing a search should not undo that.
        $this->reset('query', 'consoleFilter', 'genre', 'players', 'minRating', 'identified');
        $this->resetPage();
    }
}; ?>

{{-- The page paints to the edges, so nothing here sits inside flux:main's
     padding and every band carries its own. That is what lets the hero run the
     full width, exactly as the game page's does. --}}
<section class="w-full pb-14">
    <div class="flex flex-col">
        {{-- The shelf's art runs behind the hero and the filter row both,
             and fades into the ground just under the filters, so the page
             below starts clean. Its layers hang off this wrapper rather than
             the hero for that reason. --}}
        <div class="relative">
        @if ($this->lockedTo !== null)
            @if ($this->heroArt !== null)
                {{-- Off centre vertically, as on the game page: key art
                     puts its subject above the middle far more often than
                     not, and dead centre cuts heads off. --}}
                <div aria-hidden="true" class="absolute inset-0 bg-cover bg-[position:50%_28%]"
                     style="background-image: url('{{ route('media.show', ['path' => $this->heroArt]) }}')"></div>
            @else
                {{-- A shelf with no artwork yet is most of a new library.
                     The band keeps its height and its ground rather than
                     collapsing to the plain heading it used to be. --}}
                <div aria-hidden="true" class="absolute inset-0 bg-[linear-gradient(165deg,var(--color-raised),var(--color-sunken))]"></div>
            @endif

            <div aria-hidden="true" class="absolute inset-0 hero-fade-y"></div>

            @if ($this->heroArt !== null)
                @scanlines
                    <div aria-hidden="true" class="scanlines absolute inset-0"></div>
                @endscanlines
            @endif
        @endif

        @if ($this->lockedTo !== null)
            {{-- The shelf's hero, the game page's own: key art to the window
                 edges, a fade taking it down into the page's ground, scanlines
                 over that, and the bar of controls in the same place on both
                 pages — which is the point of matching it rather than building
                 something that merely looks similar.

                 About half the game page's band, because there is far less to
                 put on it: a console, its name and four figures, not a poster,
                 a title and a row of chips. The content sits inside the band
                 rather than being pulled up over it for the same reason.

                 The bar and the content are in the flow rather than pinned to
                 the band's edges, so below lg, where the name and the figures
                 stack, the band grows to hold them instead of the figures
                 climbing into the bar. min-h gives the desktop band the 80px
                 console, its gutter and the bar, with a tenth again of room
                 for the art. --}}
            <div class="relative flex min-h-[187px] flex-col justify-between lg:min-h-[209px]">
                {{-- pl-14 clears the floating hamburger, which sits at top-4
                     left-4. The same offsets as the game page's bar, so the
                     Actions button does not move between the two. --}}
                <div class="relative flex items-center gap-2.5 pt-4 pr-4 pl-18 sm:gap-3.5 lg:px-7.5 lg:pt-5.5">
                    {{-- The way back up, where the back button used to be. It
                         gives way before the search does on a phone: the last
                         step truncates, and the hero below names the console
                         in full anyway. --}}
                    <x-breadcrumbs :items="[
                        [__('Consoles'), route('consoles.index')],
                        [$this->lockedTo->name, null],
                    ]" />

                    {{-- Up here rather than in the filter row, which it used to
                         fill half of. It pushes Actions to the right edge, and
                         shrinks before anything else does on a phone. --}}
                    <x-search-field wire:model.live.debounce.300ms="query" class="ml-auto w-full max-w-64 flex-1" />

                    <flux:dropdown position="bottom" align="end">
                        <button
                            type="button"
                            class="flex cursor-pointer items-center gap-1.75 rounded-lg border border-line-input bg-scrim/60 px-3 py-1.75 text-sm text-fg-soft backdrop-blur-sm transition-colors hover:border-line-bright focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
                        >
                            <flux:icon.ellipsis-horizontal class="size-3.5" />
                            <span class="max-sm:sr-only">{{ __('Actions') }}</span>
                            <flux:icon.chevron-down class="size-[11px] text-fg-dim max-sm:hidden" />
                        </button>

                        <flux:menu>
                            <flux:menu.item icon="arrow-path" wire:click="scanConsole">
                                {{ __('Scan folder') }}
                            </flux:menu.item>

                            @if ($this->canUpload)
                                <flux:menu.item icon="arrow-up-tray" x-on:click="$dispatch('upload-roms')">
                                    {{ __('Upload files') }}
                                </flux:menu.item>
                            @endif

                            {{-- The only actions here that write into somebody's
                                 library, so each says where it writes before it
                                 does it. Offered only where the loader that reads
                                 those folders is the one in use, and each named by
                                 the console's toolbox. --}}
                            @foreach ($this->consoleExports as $export)
                                <flux:menu.item icon="arrow-down-tray"
                                                wire:click="writeConsoleExport('{{ $export }}')"
                                                wire:confirm="{{ $this->consoleTools->exportConfirm($export, (string) App\Models\ConsoleSourceFolder::pathFor($this->lockedTo)) }}">
                                    {{ $this->consoleTools->exportLabel($export) }}
                                </flux:menu.item>
                            @endforeach

                            {{-- Opens a preview first, so it needs no confirm of its own. --}}
                            @if ($this->canRename)
                                <flux:menu.item icon="pencil-square" x-on:click="$dispatch('rename-files')">
                                    {{ __('Rename files') }}
                                </flux:menu.item>
                            @endif

                            @if ($this->canOrganize)
                                <flux:menu.item icon="folder-open" x-on:click="$dispatch('organize-games')">
                                    {{ __('Organize into game folders') }}
                                </flux:menu.item>
                            @endif

                            <flux:menu.separator />

                            {{-- Every identified game, one copy each, to a drive
                                 or a share. Opens a preview first. --}}
                            <flux:menu.item icon="arrow-up-tray" x-on:click="$dispatch('send-console')">
                                {{ __('Send all games to…') }}
                            </flux:menu.item>

                            <flux:menu.separator />

                            <flux:menu.item icon="photo" wire:click="fetchConsoleMedia">
                                {{ __('Fetch missing artwork') }}
                            </flux:menu.item>

                            {{-- Confirmed, and the count is in the question: one
                                 provider lookup per identified game, and on a
                                 large console that is a visible bite out of the
                                 day's allowance. --}}
                            <flux:menu.item icon="arrow-path"
                                            wire:click="fetchConsoleMedia(true)"
                                            wire:confirm="{{ __('Re-fetch artwork for all :count identified games on :console? That is one provider lookup each.', [
                                                'count' => $this->consoleStats['identified'] ?? 0,
                                                'console' => $this->lockedTo->name,
                                            ]) }}">
                                {{ __('Re-fetch all artwork') }}
                            </flux:menu.item>

                            <flux:menu.separator />

                            <flux:menu.item icon="star" wire:click="fetchConsoleRatings">
                                {{ __('Fetch missing ratings') }}
                            </flux:menu.item>

                            <flux:menu.item icon="arrow-path"
                                            wire:click="fetchConsoleRatings(true)"
                                            wire:confirm="{{ __('Re-fetch ratings for all :count identified games on :console? That is one provider lookup each.', [
                                                'count' => $this->consoleStats['identified'] ?? 0,
                                                'console' => $this->lockedTo->name,
                                            ]) }}">
                                {{ __('Re-fetch all ratings') }}
                            </flux:menu.item>

                            <flux:menu.separator />

                            {{-- Straight into this console's settings, modal
                                 and all: the page reads the key off the query
                                 string and opens the form on arrival, so it is
                                 one step from a shelf rather than a page and
                                 then a hunt through 135 rows. --}}
                            <flux:menu.item icon="cog-6-tooth"
                                            href="{{ route('console-config.edit', ['console' => $this->lockedTo->key]) }}"
                                            wire:navigate>
                                {{ __('Manage console') }}
                            </flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>

                {{-- One row, centred against each other: who this is on the
                     left, what is in it on the right. The same gutters the
                     bands below use, so the console's name starts on the same
                     line as the search box under it.

                     justify-between rather than ms-auto on the figures, so the
                     two blocks wrap onto separate lines on a narrow screen
                     instead of the numbers being pushed off the edge. --}}
                <div class="relative flex flex-col gap-4 px-4 pt-8 pb-11 lg:flex-row lg:items-center lg:justify-between lg:gap-8 lg:px-8 lg:pt-6 lg:pb-6">
                    <div class="flex min-w-0 items-center gap-4">
                        {{-- The machine itself, at the size it can actually be
                             read at. Everything else in this block is text, so
                             it is the one thing that says which shelf this is
                             before a word is read. --}}
                        <img src="{{ $this->lockedTo->icon }}" alt="" class="size-14 shrink-0 object-contain lg:size-20" />

                        <div class="min-w-0">
                            <h1 class="text-display font-medium tracking-display text-fg-bright">{{ $this->lockedTo->name }}</h1>

                            {{-- Who made it and when, under the name. Joined by
                                 a middot rather than laid out in two slots, so
                                 an entry with no year — MAME and the other
                                 front-ends, which were never released as a
                                 machine — reads as a maker alone and not as a
                                 gap where a number should be. --}}
                            @php
                                $rowMaker = array_values(array_filter([
                                    $this->lockedTo->brand ?: null,
                                    $this->lockedTo->released,
                                ]));
                            @endphp

                            @if ($rowMaker !== [])
                                <p class="mt-1 truncate text-sm text-fg-dim">{{ implode(' · ', $rowMaker) }}</p>
                            @endif
                        </div>
                    </div>

                    @if ($this->consoleStats !== null)
                        {{-- Figures rather than a sentence, and each one labelled
                             under itself: a header is read at a glance and a
                             number with its name beneath it survives that.

                             At lg, text-end so the column of numbers hangs off
                             the right edge rather than off its own label, which
                             is what makes them read as a set. Below it they sit
                             under the name, left-aligned, two by two on a phone. --}}
                        <dl class="grid grid-cols-2 gap-x-8 gap-y-3 sm:flex sm:flex-wrap sm:items-end lg:text-end">
                            {{-- Only where the console offers more than one
                                 arrangement: the folders on the share are read
                                 that way, and getting it wrong is what an empty
                                 shelf usually means. First, so the numbers stay
                                 together at the right edge. --}}
                            @if ($this->layoutLabel !== null)
                                <div class="min-w-0">
                                    <dt class="kicker text-fg-faint">{{ __('Layout') }}</dt>
                                    <dd class="font-mono text-lg text-fg-bright max-sm:text-base">{{ $this->layoutLabel }}</dd>
                                </div>
                            @endif

                            <div>
                                <dt class="kicker text-fg-faint">{{ __('Games') }}</dt>
                                <dd class="font-mono text-lg text-fg-bright tabular-nums">{{ Number::format($this->consoleStats['games']) }}</dd>
                            </div>

                            <div>
                                <dt class="kicker text-fg-faint">{{ __('Files') }}</dt>
                                <dd class="font-mono text-lg text-fg-bright tabular-nums">{{ Number::format($this->consoleStats['files']) }}</dd>
                            </div>

                            <div>
                                <dt class="kicker text-fg-faint">{{ __('On disk') }}</dt>
                                <dd class="font-mono text-lg text-fg-bright tabular-nums">{{ $this->consoleStats['size'] }}</dd>
                            </div>

                            {{-- Only where the console has sets at all. A zero
                                 here would say nobody has unlocked anything,
                                 which is a different thing from there being
                                 nothing to unlock. --}}
                            @if ($this->consoleStats['possible'] > 0)
                                <div>
                                    <dt class="kicker text-fg-faint">{{ __('Achievements') }}</dt>
                                    <dd class="font-mono text-lg text-fg-bright tabular-nums">
                                        {{ $this->consoleStats['unlocked'] }} / {{ $this->consoleStats['possible'] }}
                                    </dd>
                                </div>
                            @endif
                        </dl>
                    @endif
                </div>
            </div>

            {{-- Under the hero, where a banner for a send in progress reads as
                 news about this console. Also holds the Send all modal. --}}
            <livewire:games.send-console :console="$this->lockedTo->key" wire:key="send-console-{{ $this->lockedTo->key }}" />
        @else
            {{-- No hero here: there is no one console to be specific about. The
                 padding the layout used to supply is this band's own now, and
                 max-lg:pt-16 is what clears the floating hamburger that a
                 bleeding page has to get out of the way of itself. --}}
            <div class="flex min-w-0 flex-wrap items-end justify-between gap-3 px-4 pt-6 max-lg:pt-16 lg:px-8 lg:pt-8">
                <div class="min-w-0">
                    <p class="kicker mb-1.5 text-fg-faint">{{ __('Library') }}</p>
                    <h1 class="text-display font-medium tracking-display text-fg-bright">{{ __('Games') }}</h1>
                </div>

                {{-- Beside the heading, where the console shelf has it in its
                     bar: out of the filter row on both pages alike. --}}
                <x-search-field wire:model.live.debounce.300ms="query" class="w-full sm:max-w-64" />
            </div>
        @endif

        <div class="relative px-4 pt-6 pb-6 lg:px-8 lg:pt-7">
        {{-- Three jobs in one row, ruled off from each other: which part of
             the library to look in, how to order what comes back, and how to
             draw it. Search lives up in the hero bar, beside Actions.

             The rules are hidden below lg. The row wraps onto two or three
             lines there, and a vertical rule at the end of a wrapped line
             points at nothing. --}}
        <div class="flex flex-wrap items-end gap-3">
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

            {{-- Left out where the shelf has only one answer, or none: a select
                 whose whole list is "1" filters nothing and takes the width of
                 one that does. --}}
            @if ($this->playerCounts->count() > 1)
                <flux:select wire:model.live="players" size="sm" class="w-44">
                    <flux:select.option value="">{{ __('Any players') }}</flux:select.option>
                    @foreach ($this->playerCounts as $option)
                        <flux:select.option value="{{ $option }}">{{ $option }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:select wire:model.live="minRating" size="sm" class="w-44">
                <flux:select.option value="">{{ __('Any rating') }}</flux:select.option>
                <flux:select.option value="90">{{ __('90 and above') }}</flux:select.option>
                <flux:select.option value="80">{{ __('80 and above') }}</flux:select.option>
                <flux:select.option value="70">{{ __('70 and above') }}</flux:select.option>
                <flux:select.option value="60">{{ __('60 and above') }}</flux:select.option>
            </flux:select>

            <flux:select wire:model.live="identified" size="sm" class="w-44">
                <flux:select.option value="">{{ __('Any status') }}</flux:select.option>
                <flux:select.option value="needed">{{ __('Needs identifying') }}</flux:select.option>
                <flux:select.option value="identified">{{ __('Identified') }}</flux:select.option>
            </flux:select>

            @if ($query !== '' || $consoleFilter !== '' || $genre !== '' || $players !== '' || $minRating !== '' || $identified !== '')
                <flux:button size="sm" variant="ghost" wire:click="clear">{{ __('Clear') }}</flux:button>
            @endif

            <div aria-hidden="true" class="mb-1.5 hidden h-6 w-px shrink-0 bg-line lg:block"></div>

            <flux:select wire:model.live="sort" size="sm" class="w-44">
                <flux:select.option value="title">{{ __('Title, A to Z') }}</flux:select.option>
                <flux:select.option value="rating">{{ __('Best rated first') }}</flux:select.option>
                <flux:select.option value="year">{{ __('Year, newest first') }}</flux:select.option>
                <flux:select.option value="newest">{{ __('Recently added') }}</flux:select.option>
            </flux:select>

            {{-- Pushed to the end of the row: it is not a filter, and on a
                 narrow screen it should wrap away from them rather than
                 between two selects. The rule travels with it. --}}
            <div aria-hidden="true" class="mb-1.5 ms-auto hidden h-6 w-px shrink-0 bg-line lg:block"></div>

            {{-- The docs viewer's Preview / Markdown pill, with icons: amber
                 for the view in force, and one border round the pair. --}}
            <div class="flex items-center gap-0.5 rounded-lg border border-line-input p-0.5 max-lg:ms-auto">
                @foreach (['cards' => ['icon' => 'squares-2x2', 'label' => __('Show covers')], 'table' => ['icon' => 'list-bullet', 'label' => __('Show a list')]] as $mode => ['icon' => $icon, 'label' => $label])
                    <button
                        type="button"
                        wire:click="setView('{{ $mode }}')"
                        aria-pressed="{{ $this->viewMode === $mode ? 'true' : 'false' }}"
                        aria-label="{{ $label }}"
                        title="{{ $label }}"
                        @class([
                            'grid cursor-pointer place-items-center rounded-md px-2 py-1.25 transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep',
                            'bg-accent-tint/15 text-accent' => $this->viewMode === $mode,
                            'text-fg-dim hover:bg-hover hover:text-fg' => $this->viewMode !== $mode,
                        ])
                    >
                        <flux:icon :name="$icon" class="size-4" />
                    </button>
                @endforeach
            </div>
        </div>
        </div>
        </div>

        <div class="flex flex-col gap-6 px-4 lg:px-8">
        @if ($this->games->isEmpty())
            <div class="rounded-xl border border-dashed border-line-input px-6 py-10 text-center">
                <p class="text-sm text-fg-soft">{{ __('Nothing matches that.') }}</p>
                <p class="mt-1 text-sm text-fg-faint">{{ __('Scan a console to fill the library.') }}</p>
            </div>
        @elseif ($this->viewMode === 'cards')
            {{--
                A fixed column count per breakpoint rather than wrapping on
                whatever fits: the cap is the point, so a row holds the same
                number of games on a laptop every time instead of reflowing
                by a column each time the window moves.

                The counts are set against the content column — the viewport
                less the 16rem sidebar and the page's own padding — so a cell
                is wide enough for a cover at the sizes config asks for.
                Covers are not stretched to the cell; a card is as wide as its
                own art, which is why the cells are left-aligned and the rows
                start at the top rather than being levelled to the tallest.
            --}}
            <ul class="grid grid-cols-2 items-start justify-items-start gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6">
                @foreach ($this->games as $game)
                    <li wire:key="card-{{ $game->id }}" class="w-full">
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
            {{-- Read once for the table rather than per row: it is one setting
                 and a page holds twenty-four games. --}}
            @php
                $hardcorePrimary = AppSetting::enabled(AppSetting::RA_HARDCORE_PRIMARY);
            @endphp

            {{-- Two text lines tall, with a square cover at the left edge.

                 The second line carries what used to be the Console and Genre
                 columns. A row this tall has the space for them, and folding
                 them in is what buys the width the achievement bar needs — the
                 table was already at the point where genre had to be truncated
                 to fit.

                 The cover frame is square whatever the art is, because a column
                 whose width follows the picture makes every row start at a
                 different place. object-contain rather than cover: box art is
                 tall, wide and everything between, and cropping it to a square
                 cuts the title off the box. --}}
            {{-- table-fixed, and every column but the title is pinned in the
                 colgroup below. Two things need it: a column whose width
                 follows its longest cell moves as you page through the library,
                 so the same shelf looks different on page two; and truncate on
                 the title does nothing at all until the cell it sits in has a
                 width of its own. The title takes whatever is left. --}}
            <div class="overflow-hidden rounded-xl border border-line">
                <table class="w-full table-fixed text-sm">
                    <colgroup>
                        <col class="w-20" />  {{-- cover: a 48px square and its gutter --}}
                        <col />               {{-- title: the rest --}}
                        <col class="w-20" />  {{-- year --}}
                        <col class="w-24" />  {{-- players --}}
                        <col class="w-20" />  {{-- rating --}}
                        <col class="w-44" />  {{-- achievements: the bar plus its count --}}
                        <col class="w-20" />  {{-- files --}}
                        <col class="w-20" />  {{-- actions --}}
                    </colgroup>

                    <thead class="bg-sunken text-left text-fg-faint">
                        <tr>
                            <th class="py-2.5 pl-4"><span class="sr-only">{{ __('Cover') }}</span></th>
                            <th class="px-4 py-2.5 font-medium">{{ __('Title') }}</th>
                            <th class="px-4 py-2.5 font-medium">{{ __('Year') }}</th>
                            <th class="px-4 py-2.5 font-medium">{{ __('Players') }}</th>
                            <th class="px-4 py-2.5 font-medium">{{ __('Rating') }}</th>
                            <th class="px-4 py-2.5 font-medium">{{ __('Achievements') }}</th>
                            <th class="px-4 py-2.5 font-medium">{{ __('Files') }}</th>
                            <th class="px-4 py-2.5"><span class="sr-only">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->games as $game)
                            @php
                                $rowConsole = $game->console();
                                $rowCover = $game->artwork(MediaKind::Cover);

                                // Selected off the joined progress row by the
                                // query above, and null for a game with no set.
                                $rowPossible = (int) ($game->ra_achievements_possible ?? 0);
                                $rowUnlocked = (int) (($hardcorePrimary ? $game->ra_unlocked_hardcore : $game->ra_unlocked) ?? 0);
                                $rowPercent = $rowPossible > 0 ? (int) round($rowUnlocked / $rowPossible * 100) : 0;

                                // Why each menu item cannot run, or null. Read
                                // here rather than in the menu so the row pays
                                // for them once whether or not it is opened.
                                // The provider's date is a contributed string
                                // in no fixed shape — "1995", "1995-09-09" and
                                // "09/1995" all turn up. The year is the part
                                // that is always first and always four digits.
                                $rowYear = Str::substr((string) $game->release_date, 0, 4) ?: null;

                                $rowIdentifyBlocked = $game->blockedFromLookup();
                                $rowMediaBlocked = $game->blockedFromMediaScrape();
                                $rowRatingBlocked = $game->blockedFromRating();

                                // The line under the title. Console only where
                                // the page is not already one console's shelf.
                                $rowMeta = array_values(array_filter([
                                    $this->lockedTo === null ? ($rowConsole?->name ?? $game->console) : null,
                                    $game->genre ?: null,
                                ]));
                            @endphp

                            <tr wire:key="game-{{ $game->id }}" class="border-t border-line hover:bg-hover">
                                <td class="py-2 pl-4">
                                    <div class="flex size-12 items-center justify-center overflow-hidden rounded-md border border-line-strong bg-sunken">
                                        @if ($rowCover !== null)
                                            <img
                                                src="{{ $rowCover->url(App\Enums\ThumbnailSize::List) }}"
                                                alt="{{ $game->title }}"
                                                loading="lazy"
                                                class="size-full object-contain"
                                            />
                                        @elseif ($rowConsole !== null)
                                            <img src="{{ $rowConsole->fileIcon }}" alt="{{ $rowConsole->name }}" class="size-7 object-contain opacity-25" />
                                        @else
                                            <flux:icon.photo class="size-4 text-fg-faint" />
                                        @endif
                                    </div>
                                </td>

                                <td class="px-4 py-2">
                                    <a href="{{ route('games.show', $game->routeParameters()) }}" wire:navigate class="block truncate font-medium text-fg-bright hover:text-accent">
                                        {{ $game->title }}
                                    </a>

                                    {{-- Always rendered, even empty: an absent
                                         second line would make the row half a
                                         height shorter than the one above it. --}}
                                    <p class="truncate text-xs text-fg-dim" title="{{ implode(' · ', $rowMeta) }}">
                                        {{ $rowMeta === [] ? '—' : implode(' · ', $rowMeta) }}
                                    </p>
                                </td>

                                <td class="px-4 py-2 font-mono text-fg-soft tabular-nums">{{ $rowYear ?? '—' }}</td>

                                <td class="truncate px-4 py-2 text-fg-soft" title="{{ $game->players }}">
                                    {{ $game->players ?: '—' }}
                                </td>

                                <td class="px-4 py-2 font-mono text-fg-soft tabular-nums">{{ $game->rating ?? '—' }}</td>

                                <td class="px-4 py-2">
                                    @if ($rowPossible > 0)
                                        {{-- The same bar and the same counts as
                                             the card, so a game does not report
                                             different progress in the two views. --}}
                                        <div class="flex w-32 items-center gap-2">
                                            <div class="h-1 flex-1 overflow-hidden rounded-sm bg-raised">
                                                <div class="h-full rounded-sm bg-accent-deep" style="width: {{ $rowPercent }}%"></div>
                                            </div>
                                            <span
                                                class="shrink-0 font-mono text-xs text-fg-dim tabular-nums"
                                                title="{{ $hardcorePrimary ? __('Hardcore achievements') : __('Achievements') }}"
                                            >{{ $rowUnlocked }} / {{ $rowPossible }}</span>
                                        </div>
                                    @else
                                        <span class="font-mono text-fg-faint">—</span>
                                    @endif
                                </td>

                                <td class="px-4 py-2 text-fg-soft">{{ $game->files_count }}</td>
                                {{-- A menu rather than the one button the row
                                     used to carry. That button showed Identify
                                     or Artwork, never both, so whichever the
                                     row was not offering could only be reached
                                     by opening the game. These are the same
                                     actions the console's own menu runs over a
                                     whole shelf, now aimed at one game.

                                     Each item is drawn whether or not it can
                                     run, disabled and carrying the reason, so
                                     the menu holds still between rows and says
                                     why instead of hiding. --}}
                                <td class="px-4 py-2 text-right">
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal"
                                                     :aria-label="__('Actions')" />

                                        <flux:menu>
                                            <flux:menu.item icon="sparkles"
                                                            :disabled="$rowIdentifyBlocked !== null"
                                                            :title="$rowIdentifyBlocked"
                                                            wire:click="identify({{ $game->id }})">
                                                {{ $game->status === GameStatus::Unmatched ? __('Try identifying again') : __('Identify game') }}
                                            </flux:menu.item>

                                            <flux:menu.separator />

                                            <flux:menu.item icon="photo"
                                                            :disabled="$rowMediaBlocked !== null"
                                                            :title="$rowMediaBlocked"
                                                            wire:click="fetchMedia({{ $game->id }})">
                                                {{-- The cover, not the whole
                                                     relation: this list eager-loads
                                                     covers alone, so media->isEmpty()
                                                     here would be a claim about
                                                     artwork the row never loaded. --}}
                                                {{ $rowCover === null ? __('Fetch artwork') : __('Fetch artwork again') }}
                                            </flux:menu.item>

                                            <flux:menu.separator />

                                            <flux:menu.item icon="star"
                                                            :disabled="$rowRatingBlocked !== null"
                                                            :title="$rowRatingBlocked"
                                                            wire:click="fetchRating({{ $game->id }})">
                                                {{ $game->rating === null ? __('Fetch rating') : __('Fetch rating again') }}
                                            </flux:menu.item>

                                            <flux:menu.separator />

                                            <flux:menu.item icon="arrow-top-right-on-square"
                                                            href="{{ route('games.show', $game->routeParameters()) }}"
                                                            wire:navigate>
                                                {{ __('Open game') }}
                                            </flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
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
    </div>

    {{-- Mounted only while the setting is on, so the uploader is not on the
         page at all otherwise. The chunk route stays up regardless. --}}
    @if ($this->canOrganize)
        <livewire:games.organize-modal :console="$this->lockedTo->key" wire:key="organize-modal-{{ $this->lockedTo->key }}" />
    @endif

    @if ($this->canRename)
        <livewire:games.rename-modal :console="$this->lockedTo->key" wire:key="rename-modal-{{ $this->lockedTo->key }}" />
    @endif

    @if ($this->canUpload)
        <livewire:games.upload-modal :console="$this->lockedTo->key" wire:key="upload-modal-{{ $this->lockedTo->key }}" />
    @endif
</section>
