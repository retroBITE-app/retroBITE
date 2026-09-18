<?php

use App\Jobs\ScanConsoleFolder;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Support\Console;
use App\Support\Scanning\LibraryFolders;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Consoles')] class extends Component
{
    public const MODAL = 'add-console';

    /** Narrows the list of consoles to choose from. 135 of them is a lot to scroll. */
    public string $search = '';

    /** The console being added, once one has been chosen and its folder is missing. */
    public string $adding = '';

    public string $chosenFolder = '';

    /**
     * The consoles in the library, with their counts.
     *
     * Not every console with a directory on disk: a collection copied wholesale
     * leaves a hundred folders behind, and listing all of them buries the four
     * that hold games.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function added(): Collection
    {
        $counts = Game::query()
            ->selectRaw('console, count(*) as games, sum(status = ?) as identified', ['matched'])
            ->groupBy('console')
            ->get()
            ->keyBy('console');

        return ConsoleSourceFolder::consoles()->map(fn (Console $console) => [
            'console' => $console,
            'games' => (int) ($counts[$console->key]->games ?? 0),
            'identified' => (int) ($counts[$console->key]->identified ?? 0),
            'folder' => ConsoleSourceFolder::pathFor($console),
        ])->values();
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
            ->filter(function (Console $console) use ($needle) {
                if ($needle === '') {
                    return true;
                }

                // Brand as well as name, so "sega" finds the Mega Drive.
                return str_contains(mb_strtolower($console->name.' '.$console->brand.' '.$console->key), $needle);
            })
            ->sortBy('name')
            ->values();
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

    /**
     * Work still queued, so the page can say something is happening.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function queued(): array
    {
        $rows = DB::table('jobs')->selectRaw('queue, count(*) as total')->groupBy('queue')->pluck('total', 'queue');

        return [
            'scanning' => (int) ($rows['default'] ?? 0),
            'identifying' => (int) ($rows['scraper'] ?? 0),
            'artwork' => (int) ($rows['media'] ?? 0),
        ];
    }

    public function openAdd(): void
    {
        $this->reset('search', 'adding', 'chosenFolder');

        Flux::modal(self::MODAL)->show();
    }

    public function closeAdd(): void
    {
        $this->reset('search', 'adding', 'chosenFolder');

        Flux::modal(self::MODAL)->close();
    }

    /**
     * Choose a console to add.
     *
     * Convention first: when games_path/{folder} is already there, nothing
     * needs asking and the scan starts. The picker is only for the case it is
     * not, which is the whole reason it exists.
     */
    public function choose(string $key): void
    {
        $console = Console::tryFrom($key);

        if ($console === null) {
            return;
        }

        if ($console->installed()) {
            ConsoleSourceFolder::add($console);
            $this->closeAdd();
            $this->scan($console->key);

            return;
        }

        $this->adding = $key;
        $this->chosenFolder = '';
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

        ConsoleSourceFolder::add($console, $this->chosenFolder);

        $key = $console->key;
        $this->closeAdd();

        Flux::toast(variant: 'success', text: __(':console now reads from :folder.', [
            'console' => $console->name,
            'folder' => trim($this->chosenFolder, '/'),
        ]));

        $this->scan($key);
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

    /** Queue a scan. Never runs here: a large library takes minutes to walk. */
    public function scan(string $key): void
    {
        $console = Console::tryFrom($key);

        if ($console === null) {
            return;
        }

        ScanConsoleFolder::dispatch($console->key);

        unset($this->queued, $this->added);

        Flux::toast(text: __('Scanning :console. The library fills in as it goes.', ['console' => $console->name]));
    }
}; ?>

<section class="w-full">
    <div class="flex flex-col gap-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-0">
                <p class="kicker mb-1.5 text-fg-faint">{{ __('Library') }}</p>
                <h1 class="text-display font-medium tracking-display text-fg-bright">{{ __('Consoles') }}</h1>
            </div>

            <flux:button size="sm" variant="primary" icon="plus" wire:click="openAdd">
                {{ __('Add console') }}
            </flux:button>
        </div>

        {{-- Only polls while there is something to watch, so an idle page is
             not asking the database every two seconds. --}}
        @if (array_sum($this->queued) > 0)
            <div wire:poll.2s class="flex flex-wrap items-center gap-6 rounded-xl border border-accent-tint bg-accent-tint px-5 py-4">
                <flux:icon.arrow-path class="size-4 animate-spin text-accent" />
                @foreach ($this->queued as $label => $count)
                    @if ($count > 0)
                        <p class="text-sm text-fg">
                            <span class="font-medium text-fg-bright">{{ $count }}</span> {{ __(ucfirst($label)) }}
                        </p>
                    @endif
                @endforeach
            </div>
        @endif

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
                    <div wire:key="added-{{ $row['console']->key }}"
                         class="group flex items-center gap-4 rounded-xl border border-line bg-surface p-4">
                        <img src="{{ $row['console']->icon }}" alt="" class="size-12 shrink-0 object-contain" />

                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-fg-bright">{{ $row['console']->name }}</p>
                            <p class="mt-0.5 truncate text-sm text-fg-faint">
                                {{ $row['games'] }} {{ __('games') }}
                                @if ($row['games'] > 0)
                                    · {{ $row['identified'] }} {{ __('identified') }}
                                @endif
                            </p>
                            <p class="mt-0.5 truncate font-mono text-xs text-fg-faint">{{ $row['folder'] }}</p>
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            <flux:button size="sm" variant="ghost" wire:click="scan('{{ $row['console']->key }}')">
                                {{ __('Scan') }}
                            </flux:button>

                            <flux:dropdown position="bottom" align="end">
                                <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" />
                                <flux:menu>
                                    <flux:menu.item icon="trash" variant="danger"
                                                    wire:click="remove('{{ $row['console']->key }}')"
                                                    wire:confirm="{{ __('Remove :console from your library? Its games stay and nothing on disk is touched.', ['console' => $row['console']->name]) }}">
                                        {{ __('Remove') }}
                                    </flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <flux:modal :name="$this::MODAL" wire:close="closeAdd" class="w-full max-w-lg">
        @if ($adding === '')
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
                                @if ($console->installed())
                                    {{-- Its folder is already there, so adding it asks nothing. --}}
                                    <flux:badge size="sm" color="green">{{ __('Folder found') }}</flux:badge>
                                @endif
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        @else
            @php($console = App\Support\Console::tryFrom($adding))
            <div class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('Where are the ROMs?') }}</flux:heading>
                    <flux:text class="mt-2">
                        {{ __('There is no :folder folder, so pick the one that holds :console.', [
                            'folder' => $console?->folder ?? $adding,
                            'console' => $console?->name ?? $adding,
                        ]) }}
                    </flux:text>
                </div>

                @if ($this->folders->isEmpty())
                    <p class="rounded-lg border border-dashed border-line-input px-4 py-6 text-center text-sm text-fg-faint">
                        {{ __('No folders under the library root. Add one over the network share first.') }}
                    </p>
                @else
                    <flux:select wire:model="chosenFolder" :label="__('Folder')">
                        <flux:select.option value="">{{ __('Choose…') }}</flux:select.option>
                        @foreach ($this->folders as $folder)
                            <flux:select.option value="{{ $folder }}">{{ $folder }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="chosenFolder" />
                @endif

                <div class="flex justify-end gap-2">
                    <flux:button size="sm" variant="ghost" wire:click="$set('adding', '')">{{ __('Back') }}</flux:button>
                    <flux:button size="sm" variant="primary" wire:click="useFolder" :disabled="$this->folders->isEmpty()">
                        {{ __('Use this folder') }}
                    </flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</section>
