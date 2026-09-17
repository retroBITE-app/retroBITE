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
    /** The console being added, while the folder picker is open. */
    public string $adding = '';

    public string $chosenFolder = '';

    public bool $showAvailable = false;

    /**
     * Consoles with a folder on disk, with their library counts.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function installed(): Collection
    {
        $counts = Game::query()
            ->selectRaw('console, count(*) as games, sum(status = ?) as identified', ['matched'])
            ->groupBy('console')
            ->get()
            ->keyBy('console');

        return Console::allInstalled()->map(fn (Console $console) => [
            'console' => $console,
            'games' => (int) ($counts[$console->key]->games ?? 0),
            'identified' => (int) ($counts[$console->key]->identified ?? 0),
            'folder' => ConsoleSourceFolder::pathFor($console),
        ])->values();
    }

    /**
     * Everything retroBite knows about that has no folder yet.
     *
     * @return Collection<int, Console>
     */
    #[Computed]
    public function available(): Collection
    {
        return Console::allAvailable()->sortBy('name')->values();
    }

    /**
     * Folders under the library root that no console has claimed.
     *
     * @return Collection<int, string>
     */
    #[Computed]
    public function folders(): Collection
    {
        $taken = Console::allInstalled()
            ->map(fn (Console $console) => ConsoleSourceFolder::pathFor($console))
            ->filter()
            ->all();

        return LibraryFolders::available($taken);
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

    /**
     * Start adding a console.
     *
     * Convention first: if games_path/{folder} is already there, nothing needs
     * asking. Only when it is missing does the picker open, which is the whole
     * reason the picker exists.
     */
    public function add(string $key): void
    {
        $console = Console::tryFrom($key);

        if ($console === null) {
            return;
        }

        if ($console->installed()) {
            $this->scan($key);

            return;
        }

        $this->adding = $key;
        $this->chosenFolder = '';
    }

    public function cancelAdd(): void
    {
        $this->adding = '';
        $this->chosenFolder = '';
    }

    /** Point a console at a folder that is not where convention says. */
    public function useFolder(): void
    {
        $console = Console::tryFrom($this->adding);

        $this->validate([
            'chosenFolder' => ['required', 'string'],
        ]);

        if ($console === null || ! LibraryFolders::contains($this->chosenFolder)) {
            $this->addError('chosenFolder', __('That folder is not inside the library.'));

            return;
        }

        ConsoleSourceFolder::updateOrCreate(
            ['console' => $console->key],
            ['path' => trim($this->chosenFolder, '/')],
        );

        $key = $console->key;
        $this->cancelAdd();

        Flux::toast(variant: 'success', text: __(':console now reads from :folder.', [
            'console' => $console->name,
            'folder' => trim($this->chosenFolder, '/') ?: $console->folder,
        ]));

        $this->scan($key);
    }

    /** Queue a scan. Never runs here: a large library takes minutes to walk. */
    public function scan(string $key): void
    {
        $console = Console::tryFrom($key);

        if ($console === null) {
            return;
        }

        ScanConsoleFolder::dispatch($console->key);

        unset($this->queued);

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

            <flux:button size="sm" variant="ghost" wire:click="$toggle('showAvailable')">
                {{ $showAvailable ? __('Hide the rest') : __('Add a console') }}
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
                            <span class="font-medium text-fg-bright">{{ $count }}</span>
                            {{ __(ucfirst($label)) }}
                        </p>
                    @endif
                @endforeach
            </div>
        @endif

        @if ($this->installed->isEmpty())
            <div class="rounded-xl border border-dashed border-line-input px-6 py-10 text-center">
                <p class="text-sm text-fg-soft">{{ __('No console has a folder yet.') }}</p>
                <p class="mt-1 text-sm text-fg-faint">{{ __('Add one below and point it at your ROMs.') }}</p>
            </div>
        @else
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->installed as $row)
                    <div class="flex items-center gap-4 rounded-xl border border-line bg-surface p-4">
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

                        <flux:button size="sm" variant="ghost" wire:click="scan('{{ $row['console']->key }}')">
                            {{ __('Scan') }}
                        </flux:button>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($showAvailable)
            <div class="rounded-xl border border-line bg-surface p-5">
                <p class="kicker mb-3 text-fg-faint">{{ __('Not set up yet') }}</p>

                <div class="flex flex-wrap gap-2">
                    @foreach ($this->available as $console)
                        <flux:button size="sm" variant="ghost" wire:click="add('{{ $console->key }}')">
                            {{ $console->name }}
                        </flux:button>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    <flux:modal name="pick-folder" :open="$adding !== ''" wire:close="cancelAdd" class="max-w-lg">
        <div class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Where are the ROMs?') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('There is no :folder folder, so pick the one that holds them.', ['folder' => $adding]) }}
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
                <flux:button size="sm" variant="ghost" wire:click="cancelAdd">{{ __('Cancel') }}</flux:button>
                <flux:button size="sm" variant="primary" wire:click="useFolder" :disabled="$this->folders->isEmpty()">
                    {{ __('Use this folder') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
