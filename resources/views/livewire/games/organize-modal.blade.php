<?php

use App\Exceptions\LibraryFileRejected;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Services\LibraryFiles;
use App\Support\Console;
use App\Tools\ConsoleTools;
use Flux\Flux;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * File a console's loose games into folders of their own.
 *
 * For a library moved onto the game folders layout: every game whose files
 * sit loose in the console's folder goes into a folder named for its title,
 * every file of it together. What the folder is called is the toolbox's
 * business, and moving the files is LibraryFiles'; this shows the plan and
 * applies it, working it out again on apply rather than taking the page's.
 */
new class extends Component
{
    public const MODAL = 'organize-games';

    #[Locked]
    public string $console;

    /** Looked up by key so the locked prop is the only thing the client holds. */
    #[Computed]
    public function target(): Console
    {
        $console = Console::tryFrom($this->console);

        abort_if($console === null, 404);

        return $console;
    }

    /**
     * What organizing would do, for the page to show.
     *
     * @return array{moves: array<int, array{title: string, folder: string, files: int}>, filed: int, unnamed: int}
     */
    #[Computed]
    public function plan(): array
    {
        $candidates = $this->candidates();

        return [
            ...$candidates,
            'moves' => collect($candidates['moves'])
                ->map(function (array $move): array {
                    ['game' => $game, 'folder' => $folder] = $move;

                    return ['title' => $game->title, 'folder' => $folder, 'files' => $game->files->count()];
                })
                ->all(),
        ];
    }

    /** Move every game the plan names, worked out afresh rather than taken from the page. */
    public function apply(LibraryFiles $files): void
    {
        $moves = $this->candidates()['moves'];

        try {
            $moved = $files->organize($this->target, $moves);
        } catch (LibraryFileRejected $e) {
            Flux::toast(variant: 'danger', text: $e->reason->label());

            return;
        }

        unset($this->plan);

        Flux::modal(self::MODAL)->close();
        $this->dispatch('library-organized');

        $left = count($moves) - $moved;

        Flux::toast(
            variant: $moved > 0 ? 'success' : 'warning',
            text: $left === 0
                ? trans_choice('Filed :count game into its own folder.|Filed :count games into folders of their own.', $moved, ['count' => $moved])
                : __('Filed :moved of :count games. The rest were left where they are: a file was missing, or the folder name was taken.', [
                    'moved' => $moved,
                    'count' => count($moves),
                ]),
        );
    }

    /**
     * The loose games and the folder each would go to, and why the rest have none.
     *
     * @return array{moves: array<int, array{game: Game, folder: string}>, filed: int, unnamed: int}
     */
    private function candidates(): array
    {
        $result = ['moves' => [], 'filed' => 0, 'unnamed' => 0];
        $tools = ConsoleTools::for($this->target);

        if ($tools === null || ! $tools->canOrganize()) {
            return $result;
        }

        $root = (string) ConsoleSourceFolder::pathFor($this->target);

        $games = Game::query()
            ->forConsole($this->target->key)
            ->with(['files' => function ($query) {
                return $query->present();
            }])
            ->orderBy('title')
            ->get();

        foreach ($games as $game) {
            if ($game->files->isEmpty()) {
                continue;
            }

            $loose = $game->files->every(function ($file) use ($root): bool {
                return ! Str::contains(Str::after($file->path, $root.'/'), '/');
            });

            if (! $loose) {
                $result['filed']++;

                continue;
            }

            $folder = $tools->folderFor($game);

            if ($folder === null) {
                $result['unnamed']++;

                continue;
            }

            $result['moves'][] = ['game' => $game, 'folder' => $folder];
        }

        return $result;
    }
}; ?>

<div x-on:organize-games.window="$flux.modal('{{ $this::MODAL }}').show()">
    @php
        ['moves' => $moves, 'filed' => $filed, 'unnamed' => $unnamed] = $this->plan;
        $count = count($moves);
    @endphp

    <flux:modal :name="$this::MODAL" class="w-full max-w-2xl">
        <div class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Organize :console into game folders', ['console' => $this->target->name]) }}</flux:heading>
                <flux:text class="mt-1">
                    {{ __('Moves each game that sits loose in the folder into a folder of its own, named for its title — a cue with its bins, or a playlist and its discs, together. Games already in a folder are left where they are.') }}
                </flux:text>
            </div>

            <div class="flex flex-col gap-3">
                <p class="text-sm text-fg-soft">
                    {{ $count > 0
                        ? trans_choice(':count game will be moved.|:count games will be moved.', $count, ['count' => $count])
                        : __('Nothing to move.') }}
                </p>

                @if ($count > 0)
                    <ul class="flex flex-col divide-y divide-line rounded-lg border border-line bg-sunken">
                        @foreach (array_slice($moves, 0, 10) as ['title' => $title, 'folder' => $folder, 'files' => $files])
                            <li class="flex items-baseline justify-between gap-3 px-3 py-2">
                                <span class="truncate text-sm text-fg-soft" title="{{ $title }}">{{ $title }}</span>
                                <span class="shrink-0 font-mono text-xs text-fg-faint">
                                    → {{ $folder }}/ · {{ trans_choice(':count file|:count files', $files, ['count' => $files]) }}
                                </span>
                            </li>
                        @endforeach
                    </ul>

                    @if ($count > 10)
                        <p class="text-xs text-fg-faint">{{ trans_choice('and :count more.|and :count more.', $count - 10, ['count' => $count - 10]) }}</p>
                    @endif
                @endif

                @if ($filed + $unnamed > 0)
                    <ul class="flex flex-col gap-0.5 text-xs text-fg-faint">
                        @if ($filed > 0)
                            <li>{{ trans_choice(':count game is already in a folder.|:count games are already in folders.', $filed, ['count' => $filed]) }}</li>
                        @endif
                        @if ($unnamed > 0)
                            <li>{{ trans_choice(':count game has no title a folder could be named for.|:count games have no title a folder could be named for.', $unnamed, ['count' => $unnamed]) }}</li>
                        @endif
                    </ul>
                @endif
            </div>

            <div class="flex items-center justify-end gap-2">
                <flux:button variant="primary" icon="folder-open" wire:click="apply" wire:loading.attr="disabled" wire:target="apply" :disabled="$count === 0">
                    {{ trans_choice('Move :count game|Move :count games', $count, ['count' => $count]) }}
                </flux:button>

                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Close') }}</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</div>
