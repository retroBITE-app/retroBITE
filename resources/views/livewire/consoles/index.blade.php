<?php

use App\Enums\GameStatus;
use App\Enums\MediaKind;
use App\Enums\ThumbnailSize;
use App\Jobs\MeasureLibrary;
use App\Jobs\ScanConsoleFolder;
use App\Jobs\ScrapeGameMedia;
use App\Jobs\WriteConsoleExports;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\Media;
use App\Support\Console;
use App\Support\CoverGeometry;
use App\Support\Layouts\Layouts;
use App\Support\LibraryPath;
use App\Support\MediaTypes;
use App\Support\Scanning\FolderCounts;
use App\Support\Scanning\LibraryFolders;
use App\Tools\ConsoleTools;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Consoles')] class extends Component
{
    public const MODAL = 'add-console';

    /** Narrows the list of consoles to choose from. 135 of them is a lot to scroll. */
    public string $search = '';

    /** The console being added, once one has been chosen. */
    public string $adding = '';

    public string $chosenFolder = '';

    /**
     * Which part of the modal is showing: 'console', 'folder' or 'layout'.
     *
     * A console is written once, at the end. Walking away from a half-finished
     * wizard leaves nothing behind, and the folder step is skipped entirely
     * when convention already answers it.
     */
    public string $step = 'console';

    /** Set while changing the layout of a console that is already in the library. */
    public bool $editingLayout = false;

    /**
     * Whether the modal is open. Its body is rendered only then: closed, it
     * was every console not yet added — a hundred rows, each with a look at
     * the disk for its folder — in the HTML of every render of this page.
     */
    public bool $modalOpen = false;

    /**
     * The consoles in the library, with their counts.
     *
     * Not every console with a directory on disk: a collection copied wholesale
     * leaves a hundred folders behind, and listing all of them buries the four
     * that hold games.
     *
     * The file count is what is on the disk, not what the database has rows
     * for — somebody who has dropped forty ISOs on the share should see forty
     * before any scan — but it is counted by MeasureLibrary in the background
     * and read back here, so rendering this page never touches the disk.
     * Identified has no on-disk answer — it is what the provider knew — so
     * that half is a query, as is the size the files take.
     *
     * @return Collection<int, array{console: Console, files: int, identified: int, unidentified: int, bytes: int, size: string, folder: string|null, layout: string|null, backdrop: string, covers: array<int, string>, aspect: string, group: string}>
     */
    #[Computed]
    public function added(): Collection
    {
        // Games are the identified rows; the rest are files the provider has
        // not named yet, which is the dashboard's "unmatched" per console.
        $counts = Game::query()
            ->selectRaw('console, count(case when status = ? then 1 end) as identified, count(*) as total', [GameStatus::Matched->value])
            ->groupBy('console')
            ->get();
        $identified = $counts->pluck('identified', 'console');
        $totals = $counts->pluck('total', 'console');

        // Its own query rather than a join on the one above: the files would
        // multiply the games and inflate both counts.
        $bytes = GameFile::query()
            ->join('games', 'games.id', '=', 'game_files.game_id')
            ->selectRaw('games.console, coalesce(sum(game_files.size_bytes), 0) as bytes')
            ->groupBy('games.console')
            ->pluck('bytes', 'console');

        // Sorted and grouped as Settings → UI says, the same as the sidebar.
        $arranged = ConsoleSourceFolder::arranged($identified);
        $covers = $this->recentCovers($arranged->flatten(1));

        return $arranged->flatMap(function (Collection $consoles, int|string $heading) use ($identified, $totals, $bytes, $covers): array {
            return $consoles->map(function (Console $console) use ($heading, $identified, $totals, $bytes, $covers): array {
                $named = (int) $identified->get($console->key, 0);
                $size = (int) $bytes->get($console->key, 0);
                $backdrop = Arr::first(Media::backdropsFor($console));

                return [
                    'console' => $console,
                    'files' => FolderCounts::gamesIn($console),
                    'identified' => $named,
                    'unidentified' => (int) $totals->get($console->key, 0) - $named,
                    'bytes' => $size,
                    'size' => Number::fileSize($size, 1),
                    'folder' => ConsoleSourceFolder::pathFor($console),
                    // Only where there was a choice to make. For the 134 consoles
                    // that know one arrangement it would say the same thing on
                    // every card and mean nothing.
                    'layout' => count(Layouts::keysFor($console)) > 1
                        ? ConsoleSourceFolder::layoutFor($console)->label()
                        : null,
                    // The best-rated game's key art, the same every visit; the
                    // shelf behind the card is the one that rotates.
                    'backdrop' => $backdrop !== null
                        ? route('media.show', ['path' => $backdrop])
                        : asset('images/default-backdrop.jpg'),
                    'covers' => Arr::get($covers, $console->key, []),
                    'aspect' => CoverGeometry::aspect($console),
                    'group' => (string) $heading,
                ];
            })->all();
        })->values();
    }

    /**
     * The cards split under the grouping's headings, in order: one section
     * with an empty label when the consoles are not grouped.
     *
     * @return list<array{label: string, rows: Collection<int, array<string, mixed>>}>
     */
    #[Computed]
    public function sections(): array
    {
        return $this->added
            ->groupBy('group')
            ->map(function (Collection $rows, int|string $label): array {
                return ['label' => (string) $label, 'rows' => $rows];
            })
            ->values()
            ->all();
    }

    /**
     * The whole library in one line above the cards, summed from them.
     *
     * @return array{consoles: int, games: int, size: string}
     */
    #[Computed]
    public function totals(): array
    {
        return [
            'consoles' => $this->added->count(),
            'games' => (int) $this->added->sum('identified'),
            'size' => Number::fileSize((int) $this->added->sum('bytes'), 1),
        ];
    }

    /**
     * Consoles not in the library yet, narrowed by the search box.
     *
     * @return Collection<int, Console>
     */
    #[Computed]
    public function choices(): Collection
    {
        $needle = trim(mb_strtolower($this->search));

        return Console::all()
            ->reject(fn (Console $console) => ConsoleSourceFolder::has($console))
            ->filter(function (Console $console) use ($needle): bool {
                return $console->matches($needle);
            })
            ->sortBy('name')
            ->values();
    }

    /**
     * Which folders are already beneath the library root, for the "Folder
     * found" badge on the consoles on offer: one read of the root rather than
     * an is_dir() for each of them.
     *
     * @return array<string, true>
     */
    #[Computed]
    public function present(): array
    {
        return LibraryFolders::topLevel();
    }

    /**
     * Folders under the library root that no console has claimed.
     *
     * @return Collection<int, string>
     */
    #[Computed]
    public function folders(): Collection
    {
        return LibraryFolders::available(
            ConsoleSourceFolder::consoles()
                ->map(fn (Console $console) => ConsoleSourceFolder::pathFor($console))
                ->filter()
                ->all()
        );
    }

    public function openAdd(): void
    {
        $this->reset('search', 'adding', 'chosenFolder', 'step', 'editingLayout');
        $this->modalOpen = true;

        Flux::modal(self::MODAL)->show();
    }

    public function closeAdd(): void
    {
        $this->reset('search', 'adding', 'chosenFolder', 'step', 'editingLayout', 'modalOpen');

        Flux::modal(self::MODAL)->close();
    }

    /**
     * Choose a console to add.
     *
     * Convention first: when games_path/{folder} is already there, the folder
     * step has nothing to ask and is skipped. Whether anything is asked at all
     * comes down to how many arrangements the console knows about — one, for
     * all but a handful, and then adding it is still a single click.
     */
    public function choose(string $key): void
    {
        $console = Console::tryFrom($key);

        if ($console === null) {
            return;
        }

        $this->adding = $key;
        $this->chosenFolder = '';

        if (! $console->installed()) {
            $this->step = 'folder';

            return;
        }

        $this->askLayoutOrFinish($console);
    }

    /**
     * The folder this console would make for itself, or null if it is there.
     *
     * Config knows the name — every console file declares one — so the folder
     * step can offer it rather than leave somebody to go and make it by hand.
     */
    #[Computed]
    public function creatable(): ?string
    {
        $console = Console::tryFrom($this->adding);

        if ($console === null || $console->folder === '' || $console->installed()) {
            return null;
        }

        return $console->folder;
    }

    /**
     * Make the console's own folder and carry on.
     *
     * Made now rather than held to the end: the rest of the wizard is about a
     * folder that has to exist before anything can be pointed at it. Walking
     * away afterwards leaves an empty directory, which the next attempt reuses.
     */
    public function createFolder(): void
    {
        $console = Console::tryFrom($this->adding);

        if ($console === null) {
            return;
        }

        try {
            app(LibraryPath::class)->createConsoleRoot($console);
        } catch (\Throwable $e) {
            // The message names an absolute path inside the container and is
            // no business of the browser's. Logged, and answered with a fixed
            // string that says where to look.
            Log::error('Could not create a console folder.', [
                'console' => $console->key,
                'reason' => $e->getMessage(),
            ]);

            $this->addError('chosenFolder', __('That folder could not be created. Check the library mount.'));

            return;
        }

        unset($this->folders, $this->creatable);

        // Left empty on purpose, so finishAdd() writes the conventional path —
        // which is what has just been made true.
        $this->chosenFolder = '';

        $this->askLayoutOrFinish($console);
    }

    /** Point a console at a folder that is not where convention says. */
    public function useFolder(): void
    {
        $console = Console::tryFrom($this->adding);

        $this->validate(['chosenFolder' => ['required', 'string']]);

        if ($console === null || ! LibraryFolders::contains($this->chosenFolder)) {
            $this->addError('chosenFolder', __('That folder is not inside the library.'));

            return;
        }

        $this->askLayoutOrFinish($console);
    }

    /**
     * Change how a console already in the library is read.
     *
     * Reopens the same modal at its last step, so a wrong choice is fixable
     * without taking the console out and putting it back.
     */
    public function changeLayout(string $key): void
    {
        $console = Console::tryFrom($key);

        if ($console === null) {
            return;
        }

        $this->reset('search', 'chosenFolder');

        $this->adding = $key;
        $this->editingLayout = true;
        $this->step = 'layout';
        $this->modalOpen = true;

        Flux::modal(self::MODAL)->show();
    }

    /**
     * Write the console down, now that everything has been asked.
     */
    #[On('layout-chosen')]
    public function finishAdd(string $console, ?string $layout = null): void
    {
        $consoleObject = Console::tryFrom($console);

        if ($consoleObject === null) {
            return;
        }

        // Null where nobody was asked, and null again for anything the console
        // does not offer — which can only have arrived by hand. Either way the
        // console's own default stands, and keeps standing if it ever changes.
        if ($layout !== null && ! Layouts::supports($consoleObject, $layout)) {
            $layout = null;
        }

        if ($this->editingLayout && $layout !== null) {
            ConsoleSourceFolder::setLayout($consoleObject, $layout);

            $made = $this->scaffoldFor($consoleObject);

            $this->closeAdd();
            unset($this->added);

            Flux::toast(variant: 'success', text: __(':console is now read as :layout.:made', [
                'console' => $consoleObject->name,
                'layout' => Layouts::make($layout)?->label() ?? $layout,
                'made' => $this->madeSentence($made),
            ]));

            $this->scan($consoleObject->key);

            return;
        }

        $folder = $this->chosenFolder !== '' ? $this->chosenFolder : null;

        ConsoleSourceFolder::add($consoleObject, $folder, $layout);

        // After the row, because the layout is only settled once it is written
        // and the gate reads the console's folder back off it.
        $made = $this->scaffoldFor($consoleObject);

        $this->closeAdd();

        if ($folder !== null || $made !== []) {
            Flux::toast(variant: 'success', text: $folder !== null
                ? __(':console now reads from :folder.:made', [
                    'console' => $consoleObject->name,
                    'folder' => trim($folder, '/'),
                    'made' => $this->madeSentence($made),
                ])
                : __(':console added.:made', [
                    'console' => $consoleObject->name,
                    'made' => $this->madeSentence($made),
                ]));
        }

        $this->scan($consoleObject->key);
    }

    /**
     * Make the folders this console's layout expects and the drive does not have.
     *
     * Only the missing ones, so choosing the same layout twice does nothing the
     * second time and a drive already arranged that way is left alone.
     *
     * One that cannot be made is logged and stepped over: the console is still
     * added and still scanned, because somewhere the app cannot write is still
     * somewhere it can read.
     *
     * @return string[] the ones that were actually created
     */
    private function scaffoldFor(Console $console): array
    {
        $gate = app(LibraryPath::class);
        $made = [];

        foreach (ConsoleSourceFolder::layoutFor($console)->scaffold() as $directory) {
            try {
                if ($gate->exists($console, $directory)) {
                    continue;
                }

                $gate->ensureDirectory($console, $directory);
                $made[] = $directory;
            } catch (\Throwable $e) {
                Log::warning('Could not make a folder the layout expects.', [
                    'console' => $console->key,
                    'directory' => $directory,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        return $made;
    }

    /**
     * " Created DVD/, CD/." — or nothing at all, which is the common case.
     *
     * @param  string[]  $made
     */
    private function madeSentence(array $made): string
    {
        if ($made === []) {
            return '';
        }

        return ' '.__('Created :folders.', [
            'folders' => implode(', ', array_map(function (string $directory): string {
                return $directory.'/';
            }, $made)),
        ]);
    }

    /** The layout step's Back button, and the escape hatch out of it. */
    #[On('layout-cancelled')]
    public function backFromLayout(): void
    {
        if ($this->editingLayout) {
            $this->closeAdd();

            return;
        }

        $this->step = $this->chosenFolder !== '' ? 'folder' : 'console';
    }

    /**
     * Ask about the layout, or write the console down where there is nothing
     * to ask.
     *
     * A console offering one arrangement is every console but a handful, and
     * putting a single-option question in front of somebody is worse than
     * putting none.
     */
    private function askLayoutOrFinish(Console $console): void
    {
        $layouts = Layouts::keysFor($console);

        if (count($layouts) > 1) {
            $this->step = 'layout';

            return;
        }

        $this->finishAdd($console->key);
    }

    /** Take a console out of the library. Its games and files stay. */
    public function remove(string $key): void
    {
        $console = Console::tryFrom($key);

        if ($console === null) {
            return;
        }

        ConsoleSourceFolder::forget($console);

        unset($this->added, $this->choices);

        Flux::toast(text: __(':console removed from your library. Nothing on disk was touched.', [
            'console' => $console->name,
        ]));
    }

    /**
     * Queue artwork for every game on a console.
     *
     * `$held` is the difference between filling the gaps and starting over:
     * without it, only games holding nothing at all are asked about, which is
     * what somebody wants after a scan. With it, every identified game on the
     * console is asked again — the way to pick up a media type that was
     * switched on after the artwork was first fetched.
     */
    public function fetchMedia(string $key, bool $held = false): void
    {
        $console = Console::tryFrom($key);

        if ($console === null) {
            return;
        }

        // Said once here rather than discovered one job at a time: with
        // nothing switched on, every one of them would return having done
        // nothing and the page would look broken.
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

    /**
     * Write a loader's own files back into a console's folder.
     *
     * The only thing here that writes to somebody's library, so it is asked for
     * explicitly and confirmed at the call site, never triggered by a scan.
     */
    public function writeExport(string $key, string $export): void
    {
        $console = Console::tryFrom($key);

        if ($console === null) {
            return;
        }

        $tools = ConsoleTools::for($console);

        // canExport() as well as the list: the item is only drawn when it is
        // true, and a call made without the menu must not get further.
        if ($tools === null || ! $tools->canExport() || ! in_array($export, $tools->exports(), true)) {
            return;
        }

        WriteConsoleExports::dispatch($console->key, $export);

        Flux::toast(text: __('Writing :console\'s :export files. Nothing else in the folder is touched.', [
            'console' => $console->name,
            'export' => Str::upper($export),
        ]));
    }

    /**
     * The exports a console can write, for the card menu.
     *
     * Which arrangement an export needs is the toolbox's own question — it is
     * the class that writes the files — so the layout key is not spelled out
     * here. Offering an item that then does nothing reads worse than not
     * offering it.
     *
     * @return string[]
     */
    public function exportsFor(Console $console): array
    {
        $tools = ConsoleTools::for($console);

        return $tools !== null && $tools->canExport() ? $tools->exports() : [];
    }

    /**
     * Count every console's files again, now, in the background.
     *
     * The page itself never reads the disk: the cards show the count the last
     * MeasureLibrary run left, and the schedule takes one every fifteen
     * minutes. This is for somebody who has just copied games in over the
     * share and does not want to wait for it. The page re-renders when the
     * counts land, on the storage signal.
     */
    public function measureLibrary(): void
    {
        MeasureLibrary::dispatch();

        Flux::toast(text: __('Counting files. The cards update as each console is done.'));
    }

    /** Queue a scan. Never runs here: a large library takes minutes to walk. */
    public function scan(string $key): void
    {
        $console = Console::tryFrom($key);

        if ($console === null) {
            return;
        }

        ScanConsoleFolder::dispatch($console->key);

        unset($this->added);

        Flux::toast(text: __('Scanning :console. The library fills in as it goes.', ['console' => $console->name]));
    }
    /**
     * The covers of the games most recently added to each console, newest first.
     *
     * One query for every console, ranked inside the database, rather than a
     * latest-three per card. Games without a cover are passed over, so a
     * console with art always shows some.
     *
     * @param  Collection<int, Console>  $consoles
     * @return array<string, array<int, string>> console key => cover URLs
     */
    private function recentCovers(Collection $consoles): array
    {
        if ($consoles->isEmpty()) {
            return [];
        }

        $ranked = Game::query()
            ->select('id')
            ->selectRaw('row_number() over (partition by console order by id desc) as shelf_rank')
            ->whereIn('console', $consoles->pluck('key')->all())
            ->whereHas('media', function (Builder $query): void {
                $query->ofKind(MediaKind::Cover);
            });

        return Game::query()
            ->joinSub($ranked, 'ranked', 'ranked.id', '=', 'games.id')
            ->where('ranked.shelf_rank', '<=', 3)
            ->orderByDesc('games.id')
            ->select('games.*')
            ->with(['media' => function (Relation $query): void {
                $query->ofKind(MediaKind::Cover);
            }])
            ->get()
            ->groupBy('console')
            ->map(function (Collection $games): array {
                return $games
                    ->map(function (Game $game): ?string {
                        return $game->artwork(MediaKind::Cover)?->url(ThumbnailSize::Grid);
                    })
                    ->filter()
                    ->values()
                    ->all();
            })
            ->all();
    }
}; ?>

{{-- Re-rendered when MeasureLibrary lands a count: the cards read the stored
     figures and nothing here touches the disk. --}}
<section
    class="w-full"
    x-data="{
        stop: null,
        init() { this.stop = live.system('storage', () => this.$wire.$refresh()) },
        destroy() { this.stop?.() },
    }"
>
    <div class="flex flex-col gap-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-0">
                <p class="kicker mb-1.5 text-fg-faint">{{ __('Library') }}</p>
                <h1 class="text-display font-medium tracking-display text-fg-bright">{{ __('Consoles') }}</h1>
                @if ($this->added->isNotEmpty())
                    <p class="mt-1.5 font-mono text-xs text-fg-dim">
                        {{ trans_choice('{1} :count console|[0,*] :count consoles', $this->totals['consoles'], ['count' => Illuminate\Support\Number::format($this->totals['consoles'])]) }}
                        · {{ trans_choice('{1} :count game|[0,*] :count games', $this->totals['games'], ['count' => Illuminate\Support\Number::format($this->totals['games'])]) }}
                        · {{ __(':size on disk', ['size' => $this->totals['size']]) }}
                    </p>
                @endif
            </div>

            <div class="flex items-center gap-2">
                <flux:button size="sm" variant="subtle" icon="arrow-path" wire:click="measureLibrary">
                    {{ __('Count files') }}
                </flux:button>

                <flux:button size="sm" variant="primary" icon="plus" wire:click="openAdd">
                    {{ __('Add console') }}
                </flux:button>
            </div>
        </div>

        @if ($this->added->isEmpty())
            <div class="rounded-xl border border-dashed border-line-input px-6 py-12 text-center">
                <p class="text-sm text-fg-soft">{{ __('Your library is empty.') }}</p>
                <p class="mt-1 text-sm text-fg-faint">
                    {{ __('Add the consoles you want to see. A folder on disk does not put one here by itself.') }}
                </p>
                <flux:button size="sm" variant="primary" icon="plus" class="mt-5" wire:click="openAdd">
                    {{ __('Add console') }}
                </flux:button>
            </div>
        @else
            {{-- One section per group from Settings → UI, each its own grid
                 under a heading; ungrouped is a single section without one. --}}
            <div class="flex flex-col gap-8">
                @foreach ($this->sections as ['label' => $label, 'rows' => $rows])
                    <section wire:key="section-{{ $loop->index }}-{{ $label }}" class="flex flex-col gap-3">
                        @if ($label !== '')
                            <div class="flex items-center gap-3">
                                <h2 class="kicker text-fg-faint">{{ $label }}</h2>
                                <span class="font-mono text-xs text-fg-dim">{{ $rows->count() }}</span>
                                <div aria-hidden="true" class="h-px flex-1 bg-line"></div>
                            </div>
                        @endif
                        {{-- As many columns as fit a card's minimum width rather than a
                             count per breakpoint: the fan takes the right of the card, so
                             the name needs room the sidebar's width decides, not the
                             viewport's. Not the shelf utility, whose two-column floor
                             would squeeze a phone into two of these. --}}
                        <div class="grid grid-cols-[repeat(auto-fill,minmax(min(26rem,100%),1fr))] gap-4">
                            @foreach ($rows as $row)
                                {{-- The same hover as a game card: the border takes the
                                     accent and the name brightens. Only the covers move. --}}
                                <div wire:key="added-{{ $row['console']->key }}"
                                     class="group relative flex flex-col overflow-hidden rounded-xl border border-line bg-surface transition-colors hover:border-accent-tint focus-within:border-accent-tint">
                                    {{-- Stretched over the card rather than wrapping it, so
                                         the menu sits above the link instead of inside it. --}}
                                    <a
                                        href="{{ route('consoles.games', ['console' => $row['console']->key]) }}"
                                        wire:navigate
                                        aria-label="{{ $row['console']->name }}"
                                        class="absolute inset-0 z-10 rounded-xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
                                    ></a>

                                    {{-- The best-rated game's key art, masked into the card
                                         rather than faded into the page ground, which would
                                         show as a seam against the card's surface. --}}
                                    <div aria-hidden="true" class="art-fade-b pointer-events-none relative h-28 shrink-0">
                                        <div class="absolute inset-0 bg-cover bg-[position:50%_28%] opacity-70 transition-opacity group-hover:opacity-90"
                                             style="background-image: url('{{ $row['backdrop'] }}')"></div>
                                        @scanlines
                                            <div class="scanlines absolute inset-0"></div>
                                        @endscanlines
                                    </div>

                                    <div class="absolute top-2.5 right-2.5 z-20">
                                        <flux:dropdown position="bottom" align="end">
                                            <button
                                                type="button"
                                                aria-label="{{ __('Actions for :console', ['console' => $row['console']->name]) }}"
                                                class="grid size-8 cursor-pointer place-items-center rounded-lg border border-line-input bg-scrim/60 text-fg-soft backdrop-blur-sm transition-colors hover:border-line-bright hover:text-fg-bright focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
                                            >
                                                <flux:icon.ellipsis-horizontal class="size-4" />
                                            </button>

                                            <flux:menu>
                                                @php
                                                    // The group under Scan is two optional
                                                    // items: most consoles know one
                                                    // arrangement and only a couple have a
                                                    // loader to write for. Without this the
                                                    // menu would draw two rules in a row.
                                                    $rowLayouts = count(App\Support\Layouts\Layouts::keysFor($row['console'])) > 1;
                                                    $rowExports = $this->exportsFor($row['console']);
                                                @endphp

                                                {{-- In here rather than on the card, where
                                                     a click meant for the console landed
                                                     on it and walked the whole folder. --}}
                                                <flux:menu.item icon="magnifying-glass"
                                                                wire:click="scan('{{ $row['console']->key }}')">
                                                    {{ __('Scan folder') }}
                                                </flux:menu.item>

                                                <flux:menu.separator />

                                                @if ($rowLayouts)
                                                    <flux:menu.item icon="folder-open"
                                                                    wire:click="changeLayout('{{ $row['console']->key }}')">
                                                        {{ __('Change layout') }}
                                                    </flux:menu.item>
                                                @endif

                                                {{-- The only actions in the app that write into the
                                                     library, so each says where it writes before it
                                                     does it. Offered only where the loader that reads
                                                     those folders is the one in use. --}}
                                                @foreach ($rowExports as $export)
                                                    <flux:menu.item icon="arrow-down-tray"
                                                                    wire:click="writeExport('{{ $row['console']->key }}', '{{ $export }}')"
                                                                    wire:confirm="{{ App\Tools\ConsoleTools::for($row['console'])?->exportConfirm($export, (string) $row['folder']) }}">
                                                        {{ App\Tools\ConsoleTools::for($row['console'])?->exportLabel($export) }}
                                                    </flux:menu.item>
                                                @endforeach

                                                @if ($rowLayouts || $rowExports !== [])
                                                    <flux:menu.separator />
                                                @endif

                                                <flux:menu.item icon="photo"
                                                                wire:click="fetchMedia('{{ $row['console']->key }}')">
                                                    {{ __('Fetch missing artwork') }}
                                                </flux:menu.item>

                                                {{-- Confirmed, and the scraper's own count
                                                     is in the question: this is one
                                                     provider lookup per identified game,
                                                     and on a large console that is a
                                                     visible bite out of the day. --}}
                                                <flux:menu.item icon="arrow-path"
                                                                wire:click="fetchMedia('{{ $row['console']->key }}', true)"
                                                                wire:confirm="{{ __('Re-fetch artwork for all :count identified games on :console? That is one provider lookup each.', ['count' => $row['identified'], 'console' => $row['console']->name]) }}">
                                                    {{ __('Re-fetch all artwork') }}
                                                </flux:menu.item>

                                                <flux:menu.separator />

                                                {{-- The same destination the console's own
                                                     shelf offers, so there is one place a
                                                     console is configured and one way in
                                                     from either list. --}}
                                                <flux:menu.item icon="cog-6-tooth"
                                                                href="{{ route('console-config.edit', ['console' => $row['console']->key]) }}"
                                                                wire:navigate>
                                                    {{ __('Manage console') }}
                                                </flux:menu.item>

                                                <flux:menu.separator />

                                                <flux:menu.item icon="trash" variant="danger"
                                                                wire:click="remove('{{ $row['console']->key }}')"
                                                                wire:confirm="{{ __('Remove :console from your library? Its games stay and nothing on disk is touched.', ['console' => $row['console']->name]) }}">
                                                    {{ __('Remove') }}
                                                </flux:menu.item>
                                            </flux:menu>
                                        </flux:dropdown>
                                    </div>

                                    {{-- The newest three covers, fanned across the seam
                                         between the art and the card, newest in front.
                                         They spread a little under the pointer. --}}
                                    @if ($row['covers'] !== [])
                                        @php
                                            $portrait = App\Support\CoverGeometry::orientation($row['console']) === 'portrait';
                                            $fan = [
                                                'z-3 -translate-x-1/2 motion-safe:group-hover:-translate-y-1',
                                                'z-2 -translate-x-[92%] -rotate-8 motion-safe:group-hover:-translate-x-[115%] motion-safe:group-hover:-rotate-12',
                                                'z-1 -translate-x-[8%] rotate-8 motion-safe:group-hover:translate-x-[15%] motion-safe:group-hover:rotate-12',
                                            ];
                                        @endphp
                                        <div aria-hidden="true" class="pointer-events-none absolute top-13 right-5 h-24 w-36">
                                            @foreach ($row['covers'] as $index => $cover)
                                                <img src="{{ $cover }}" alt="" loading="lazy"
                                                     style="aspect-ratio: {{ $row['aspect'] }}"
                                                     class="{{ $portrait ? 'h-24' : 'w-22' }} {{ Illuminate\Support\Arr::get($fan, $index) }} absolute bottom-0 left-1/2 origin-bottom rounded-md border border-line-input object-cover shadow-lift motion-safe:transition-[translate,rotate] motion-safe:duration-300" />
                                            @endforeach
                                        </div>
                                    @endif

                                    <div class="relative -mt-9 flex items-center gap-3.5 px-4 {{ $row['covers'] !== [] ? 'pr-42' : '' }}">
                                        <img src="{{ $row['console']->icon }}" alt="" class="size-14 shrink-0 object-contain drop-shadow-lg" />

                                        <div class="min-w-0 flex-1">
                                            <p class="truncate font-medium text-fg transition-colors group-hover:text-fg-bright">{{ $row['console']->name }}</p>
                                            <p class="mt-0.5 truncate text-sm text-fg-dim">
                                                {{-- Joined here rather than with an @if between them,
                                                     which Livewire would mark with a comment mid-line. --}}
                                                {{ collect([$row['console']->brand, $row['console']->released])->filter()->join(' · ') }}
                                            </p>
                                        </div>
                                    </div>

                                    {{--
                                        Games are what the provider identified; files are
                                        what is on the disk, and a four-disc set is four of
                                        those and one of these. The file count is read off
                                        the drive, so it is right before a scan has run.
                                        Unidentified only when there is something left to
                                        name, so a tidy console stays quiet.
                                    --}}
                                    <dl class="flex flex-wrap gap-x-6 gap-y-3 px-4 pt-6 pb-4">
                                        <div>
                                            <dt class="kicker text-fg-faint">{{ __('Games') }}</dt>
                                            <dd class="mt-1 font-mono text-lg tabular-nums text-fg-bright">{{ Illuminate\Support\Number::format($row['identified']) }}</dd>
                                        </div>
                                        @if ($row['unidentified'] > 0)
                                            <div>
                                                <dt class="kicker text-fg-faint">{{ __('Unidentified') }}</dt>
                                                <dd class="mt-1 font-mono text-lg tabular-nums text-warn">{{ Illuminate\Support\Number::format($row['unidentified']) }}</dd>
                                            </div>
                                        @endif
                                        <div>
                                            <dt class="kicker text-fg-faint">{{ __('Files') }}</dt>
                                            <dd class="mt-1 font-mono text-lg tabular-nums text-fg-bright">{{ Illuminate\Support\Number::format($row['files']) }}</dd>
                                        </div>
                                        <div>
                                            <dt class="kicker text-fg-faint">{{ __('On disk') }}</dt>
                                            <dd class="mt-1 font-mono text-lg tabular-nums text-fg-bright">{{ $row['size'] }}</dd>
                                        </div>
                                    </dl>

                                    <p class="mt-auto flex items-center gap-1.5 truncate border-t border-line px-4 py-2.5 text-xs text-fg-faint">
                                        <span class="truncate font-mono">{{ $row['folder'] }}</span>
                                        @if ($row['layout'] !== null)
                                            <span aria-hidden="true">·</span>
                                            <span class="truncate">{{ $row['layout'] }}</span>
                                        @endif
                                    </p>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        @endif
    </div>

    <flux:modal :name="$this::MODAL" wire:close="closeAdd" class="w-full max-w-lg">
        @if (! $modalOpen)
            {{-- Closed: nothing to send. See $modalOpen. --}}
        @elseif ($step === 'console')
            <div class="flex flex-col gap-4">
                <div>
                    <flux:heading size="lg">{{ __('Add a console') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('Only the ones you add appear in your library.') }}</flux:text>
                </div>

                <flux:input wire:model.live.debounce.200ms="search" icon="magnifying-glass"
                            :placeholder="__('Search by name or brand')" size="sm" autofocus />

                @if ($this->choices->isEmpty())
                    <p class="rounded-lg border border-dashed border-line-input px-4 py-6 text-center text-sm text-fg-faint">
                        {{ $search === '' ? __('Every console is already in your library.') : __('Nothing matches that.') }}
                    </p>
                @else
                    <div class="-mx-2 max-h-96 overflow-y-auto px-2">
                        @foreach ($this->choices as $console)
                            <button type="button" wire:key="choice-{{ $console->key }}"
                                    wire:click="choose('{{ $console->key }}')"
                                    class="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left transition-colors hover:bg-hover">
                                <img src="{{ $console->icon }}" alt="" class="size-8 shrink-0 object-contain" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm text-fg-bright">{{ $console->name }}</span>
                                    <span class="block truncate text-xs text-fg-faint">{{ $console->brand }}</span>
                                </span>
                                @if (isset($this->present[$console->folder]))
                                    {{-- Its folder is already there, so adding it asks nothing. --}}
                                    <flux:badge size="sm" color="green">{{ __('Folder found') }}</flux:badge>
                                @endif
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        @elseif ($step === 'layout')
            <livewire:consoles.choose-layout
                :console="$adding"
                :editing="$editingLayout"
                :key="'layout-'.$adding.'-'.($editingLayout ? 'edit' : 'add')"
            />
        @else
            @php($console = App\Support\Console::tryFrom($adding))
            <div class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('Where are the ROMs?') }}</flux:heading>
                    <flux:text class="mt-2">
                        {{ __('There is no :folder folder yet. Make one, or point :console at a folder that is already there.', [
                            'folder' => $console?->folder ?? $adding,
                            'console' => $console?->name ?? $adding,
                        ]) }}
                    </flux:text>
                </div>

                {{-- Config already knows what the folder is called, so offer it
                     rather than send somebody off to make it over the share.
                     Offered beside the picker, not instead of it: a library of
                     folders named some other way is just as common as none. --}}
                @if ($this->creatable !== null)
                    <flux:button size="sm" variant="primary" icon="folder-plus" wire:click="createFolder">
                        {{ __('Create :folder/', ['folder' => $this->creatable]) }}
                    </flux:button>
                @endif

                @if ($this->folders->isNotEmpty())
                    <flux:select wire:model="chosenFolder" :label="__('Or use an existing folder')">
                        <flux:select.option value="">{{ __('Choose…') }}</flux:select.option>
                        @foreach ($this->folders as $folder)
                            <flux:select.option value="{{ $folder }}">{{ $folder }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                <flux:error name="chosenFolder" />

                <div class="flex justify-end gap-2">
                    <flux:button size="sm" variant="ghost" wire:click="$set('step', 'console')">{{ __('Back') }}</flux:button>
                    <flux:button size="sm" variant="primary" wire:click="useFolder" :disabled="$this->folders->isEmpty()">
                        {{ __('Use this folder') }}
                    </flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</section>
