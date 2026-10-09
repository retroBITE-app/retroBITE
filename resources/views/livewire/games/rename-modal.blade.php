<?php

use App\Enums\FileRole;
use App\Exceptions\LibraryFileRejected;
use App\Models\Game;
use App\Models\GameFile;
use App\Services\LibraryFiles;
use App\Support\Console;
use App\Tools\ConsoleTools;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * Add a console's identifier to its game files' names, or take it out.
 *
 * On the shelf it covers every game on the console; on a game's page, with
 * a game id, just that one. What a name becomes is the toolbox's business —
 * for PS2 it is OPL's "SLES_503.86.Title.iso" — and moving the files is
 * LibraryFiles', so this only shows the plan and applies it. The plan is
 * worked out again on apply rather than taken from the page.
 */
new class extends Component
{
    public const MODAL = 'rename-files';

    #[Locked]
    public string $console;

    /** One game's files only, from its own page; null for the whole shelf. */
    #[Locked]
    public ?int $gameId = null;

    /** 'add' or 'remove' the identifier. */
    public string $mode = 'add';

    /** On a game's page, start on whichever of the two would do something. */
    public function mount(): void
    {
        if ($this->gameId === null) {
            return;
        }

        $removable = $this->candidates(false)['renames'] !== [];

        $this->mode = $removable ? 'remove' : 'add';
    }

    /** Looked up by key so the locked prop is the only thing the client holds. */
    #[Computed]
    public function target(): Console
    {
        $console = Console::tryFrom($this->console);

        abort_if($console === null, 404);

        return $console;
    }

    /**
     * What the chosen mode would do, for the page to show.
     *
     * @return array{renames: array<int, array{from: string, to: string}>, unchanged: int, unread: int, sets: int}
     */
    #[Computed]
    public function plan(): array
    {
        $candidates = $this->candidates($this->mode === 'add');

        return [
            ...$candidates,
            'renames' => collect($candidates['renames'])
                ->map(function (array $rename): array {
                    ['file' => $file, 'to' => $to] = $rename;

                    return ['from' => $file->filename, 'to' => $to];
                })
                ->all(),
        ];
    }

    /** Rename every file the plan names, worked out afresh rather than taken from the page. */
    public function apply(LibraryFiles $files): void
    {
        $renames = $this->candidates($this->mode === 'add')['renames'];

        try {
            $renamed = $files->renameFiles($this->target, $renames);
        } catch (LibraryFileRejected $e) {
            Flux::toast(variant: 'danger', text: $e->reason->label());

            return;
        }

        unset($this->plan);

        Flux::modal(self::MODAL)->close();
        $this->dispatch('library-renamed');

        Flux::toast(
            variant: $renamed > 0 ? 'success' : 'warning',
            text: $renamed > 0
                ? trans_choice('Renamed :count file.|Renamed :count files.', $renamed, ['count' => $renamed])
                : __('Nothing was renamed: every new name was already taken.'),
        );
    }

    /**
     * The renames the toolbox proposes, and why the rest have none.
     *
     * Counted per game for a cue/bin or multi-disc set, whose files are left
     * alone as a whole; per file otherwise.
     *
     * @return array{renames: array<int, array{file: GameFile, to: string}>, unchanged: int, unread: int, sets: int}
     */
    private function candidates(bool $withLicenseId): array
    {
        $result = ['renames' => [], 'unchanged' => 0, 'unread' => 0, 'sets' => 0];
        $tools = ConsoleTools::for($this->target);

        if ($tools === null || ! $tools->canRename()) {
            return $result;
        }

        $games = Game::query()
            ->forConsole($this->target->key)
            ->when($this->gameId !== null, function ($query) {
                return $query->whereKey($this->gameId);
            })
            ->with(['files' => function ($query) {
                return $query->present()->with(['children', 'meta']);
            }])
            ->orderBy('title')
            ->get();

        foreach ($games as $game) {
            $singles = $game->files->filter(function (GameFile $file): bool {
                return $file->role === FileRole::Rom && $file->parent_id === null && $file->children->isEmpty();
            });

            if ($singles->isEmpty()) {
                $result['sets'] += $game->files->isNotEmpty() ? 1 : 0;

                continue;
            }

            foreach ($singles as $file) {
                $to = $tools->renamedFilename($file, $withLicenseId);

                if ($to !== null) {
                    $result['renames'][] = ['file' => $file, 'to' => $to];
                } elseif ($withLicenseId && $file->meta?->license_id === null) {
                    $result['unread']++;
                } else {
                    $result['unchanged']++;
                }
            }
        }

        return $result;
    }
}; ?>

<div x-on:rename-files.window="$flux.modal('{{ $this::MODAL }}').show()">
    @php
        ['renames' => $renames, 'unchanged' => $unchanged, 'unread' => $unread, 'sets' => $sets] = $this->plan;
        $count = count($renames);
    @endphp

    <flux:modal :name="$this::MODAL" class="w-full max-w-2xl">
        <div class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">
                    {{ $gameId === null ? __('Rename files on :console', ['console' => $this->target->name]) : __('Rename file') }}
                </flux:heading>
                <flux:text class="mt-1">
                    {{ __('Adds the license ID in front of each file name, or takes it off again — OPL\'s older "SLES_503.86.Title.iso" form. OPL reads the license ID from the disc either way. The rest of the name is left as it is.') }}
                </flux:text>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="kicker text-fg-dim">{{ __('License ID') }}</p>

                <div role="radiogroup" aria-label="{{ __('License ID') }}" class="flex shrink-0 items-center gap-0.5 rounded-lg border border-line-input p-0.5">
                    @foreach (['add' => __('Add'), 'remove' => __('Remove')] as $value => $label)
                        <button
                            type="button"
                            role="radio"
                            aria-checked="{{ $mode === $value ? 'true' : 'false' }}"
                            wire:click="$set('mode', '{{ $value }}')"
                            @class([
                                'cursor-pointer rounded-md px-2.75 py-1 text-xs transition-colors',
                                'bg-accent-tint/15 text-accent' => $mode === $value,
                                'text-fg-dim hover:bg-hover hover:text-fg' => $mode !== $value,
                            ])
                        >{{ $label }}</button>
                    @endforeach
                </div>
            </div>

            <div class="flex flex-col gap-3">
                <p class="text-sm text-fg-soft">
                    {{ $count > 0
                        ? trans_choice(':count file will be renamed.|:count files will be renamed.', $count, ['count' => $count])
                        : __('Nothing to rename.') }}
                </p>

                @if ($count > 0)
                    <ul class="flex flex-col divide-y divide-line rounded-lg border border-line bg-sunken">
                        @foreach (array_slice($renames, 0, 10) as ['from' => $from, 'to' => $to])
                            <li class="flex flex-col gap-0.5 px-3 py-2 font-mono text-xs">
                                <span class="truncate text-fg-faint" title="{{ $from }}">{{ $from }}</span>
                                <span class="truncate text-fg-soft" title="{{ $to }}">→ {{ $to }}</span>
                            </li>
                        @endforeach
                    </ul>

                    @if ($count > 10)
                        <p class="text-xs text-fg-faint">{{ trans_choice('and :count more.|and :count more.', $count - 10, ['count' => $count - 10]) }}</p>
                    @endif
                @endif

                @if ($unchanged + $unread + $sets > 0)
                    <ul class="flex flex-col gap-0.5 text-xs text-fg-faint">
                        @if ($unchanged > 0)
                            <li>{{ trans_choice(':count file is already named that way.|:count files are already named that way.', $unchanged, ['count' => $unchanged]) }}</li>
                        @endif
                        @if ($unread > 0)
                            <li>{{ trans_choice(':count file has no license ID read yet — scan the folder first.|:count files have no license ID read yet — scan the folder first.', $unread, ['count' => $unread]) }}</li>
                        @endif
                        @if ($sets > 0)
                            <li>{{ trans_choice(':count game is a cue/bin or multi-disc set, left alone because its sheet names the files.|:count games are cue/bin or multi-disc sets, left alone because their sheets name the files.', $sets, ['count' => $sets]) }}</li>
                        @endif
                    </ul>
                @endif
            </div>

            <div class="flex items-center justify-end gap-2">
                <flux:button variant="primary" icon="pencil-square" wire:click="apply" wire:loading.attr="disabled" wire:target="apply" :disabled="$count === 0">
                    {{ trans_choice('Rename :count file|Rename :count files', $count, ['count' => $count]) }}
                </flux:button>

                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Close') }}</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</div>
