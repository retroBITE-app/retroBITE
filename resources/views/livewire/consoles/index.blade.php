<?php

use App\Enums\GameStatus;
use App\Jobs\MeasureLibrary;
use App\Jobs\ScanConsoleFolder;
use App\Jobs\ScrapeGameMedia;
use App\Jobs\WriteConsoleExports;
use App\Models\AppSetting;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Support\Console;
use App\Support\Layouts\Layouts;
use App\Support\LibraryPath;
use App\Support\MediaTypes;
use App\Support\Scanning\FolderCounts;
use App\Support\Scanning\LibraryFolders;
use App\Tools\ConsoleTools;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
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
     * that half is a query.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function added(): Collection
    {
        $identified = Game::query()
            ->where('status', GameStatus::Matched)
            ->selectRaw('console, count(*) as total')
            ->groupBy('console')
            ->pluck('total', 'console');

        // One grouped query for every console rather than a sum per row. The
        // join is to the denormalised progress table, so nothing here counts
        // individual unlocks.
        $achievements = Game::query()
            ->leftJoin('ra_progress', function ($join): void {
                $join->on('ra_progress.ra_game_id', '=', 'games.retroachievements_id')
                    ->where('ra_progress.user_id', '=', auth()->id() ?? 0);
            })
            ->selectRaw(
                'games.console,'
                .' coalesce(sum(ra_progress.unlocked_count), 0) as unlocked,'
                .' coalesce(sum(ra_progress.unlocked_hardcore_count), 0) as unlocked_hardcore,'
                .' coalesce(sum(ra_progress.achievements_possible), 0) as possible'
            )
            ->groupBy('games.console')
            ->get()
            ->keyBy('console');

        $hardcore = AppSetting::enabled(AppSetting::RA_HARDCORE_PRIMARY);

        return ConsoleSourceFolder::consoles()->map(function (Console $console) use ($identified, $achievements, $hardcore): array {
            $progress = $achievements->get($console->key);
            $possible = (int) ($progress->possible ?? 0);

            return [
                'console' => $console,
                'files' => FolderCounts::gamesIn($console),
                'identified' => (int) $identified->get($console->key, 0),
                'folder' => ConsoleSourceFolder::pathFor($console),
                'achievements' => $possible > 0
                    ? [
                        'unlocked' => $unlocked = (int) ($hardcore ? $progress->unlocked_hardcore : $progress->unlocked),
                        'possible' => $possible,
                        'percent' => (int) round($unlocked / $possible * 100),
                        'hardcore' => $hardcore,
                    ]
                    : null,
                // Only where there was a choice to make. For the 134 consoles
                // that know one arrangement it would say the same thing on
                // every card and mean nothing.
                'layout' => count(Layouts::keysFor($console)) > 1
                    ? ConsoleSourceFolder::layoutFor($console)->label()
                    : null,
            ];
        })->values();
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
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->added as $row)
                    {{-- The same hover as a game card: the border takes the
                         accent and the name brightens, and nothing moves. --}}
                    <div wire:key="added-{{ $row['console']->key }}"
                         class="group relative flex flex-col gap-4 rounded-xl border border-line bg-surface p-4 transition-colors hover:border-accent-tint focus-within:border-accent-tint">
                        {{-- Stretched over the card rather than wrapping it, so
                             the menu sits above the link instead of inside it. --}}
                        <a
                            href="{{ route('consoles.games', ['console' => $row['console']->key]) }}"
                            wire:navigate
                            aria-label="{{ $row['console']->name }}"
                            class="absolute inset-0 z-10 rounded-xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
                        ></a>

                        <div class="flex items-start gap-4">
                            <img src="{{ $row['console']->icon }}" alt="" class="size-12 shrink-0 object-contain" />

                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium text-fg transition-colors group-hover:text-fg-bright">{{ $row['console']->name }}</p>
                                <p class="mt-0.5 truncate text-sm text-fg-faint">
                                    {{-- Joined here rather than with an @if between them,
                                         which Livewire would mark with a comment mid-line. --}}
                                    {{ collect([$row['console']->brand, $row['console']->released])->filter()->join(' · ') }}
                                </p>
                            </div>

                            <div class="relative z-20 -me-1.5 -mt-1 shrink-0">
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" />
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
                        </div>

                        {{--
                            Games first and large, files after and small. The
                            identified count is how many games there are; the
                            file count is what is on the disk, and a four-disc
                            set is four of those and one of these. The file count
                            is read off the drive, so it is right before a scan
                            has run and goes to zero when the drive is unmounted.
                        --}}
                        <div>
                            <p class="flex items-baseline gap-1.5">
                                <span class="text-2xl font-medium tabular-nums text-fg-bright">{{ Illuminate\Support\Number::format($row['identified']) }}</span>
                                <span class="text-sm text-fg-soft">{{ trans_choice('{1} game|[0,*] games', $row['identified']) }}</span>
                            </p>
                            <p class="mt-1 flex items-center gap-1.5 truncate text-xs text-fg-faint">
                                <span>{{ trans_choice('{1} :count file|[0,*] :count files', $row['files'], ['count' => Illuminate\Support\Number::format($row['files'])]) }}</span>
                                <span aria-hidden="true">·</span>
                                <span class="truncate font-mono">{{ $row['folder'] }}</span>
                                @if ($row['layout'] !== null)
                                    <span aria-hidden="true">·</span>
                                    <span class="truncate">{{ $row['layout'] }}</span>
                                @endif
                            </p>
                        </div>

                        @if ($row['achievements'] !== null)
                            {{-- The same bar and counts as a game card, summed
                                 over the console. mt-auto so it keeps to the
                                 bottom when a neighbour in the row is taller. --}}
                            <div class="mt-auto flex items-center gap-2">
                                <div class="h-1 flex-1 overflow-hidden rounded-sm bg-raised">
                                    <div class="h-full rounded-sm bg-accent-deep transition-[width] duration-300" style="width: {{ $row['achievements']['percent'] }}%"></div>
                                </div>
                                <span
                                    class="shrink-0 font-mono text-xs text-fg-dim"
                                    title="{{ $row['achievements']['hardcore'] ? __('Hardcore achievements') : __('Achievements') }}"
                                >{{ $row['achievements']['unlocked'] }} / {{ $row['achievements']['possible'] }}</span>
                            </div>
                        @endif
                    </div>
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
