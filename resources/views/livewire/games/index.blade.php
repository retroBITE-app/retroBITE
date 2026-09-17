<?php

use App\Enums\GameStatus;
use App\Models\Game;
use App\Support\Console;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Games')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $query = '';

    #[Url(as: 'console', except: '')]
    public string $console = '';

    /** '' | placeholder | matched | unmatched */
    #[Url(as: 'status', except: '')]
    public string $status = '';

    public function updated(string $property): void
    {
        // Any change to a filter invalidates the page you were on.
        if (in_array($property, ['query', 'console', 'status'], true)) {
            $this->resetPage();
        }
    }

    /** @return LengthAwarePaginator<int, Game> */
    #[Computed]
    public function games(): LengthAwarePaginator
    {
        return Game::query()
            ->when($this->query !== '', fn ($q) => $q->where('title', 'like', '%'.$this->query.'%'))
            ->when($this->console !== '', fn ($q) => $q->forConsole($this->console))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->withCount('files')
            ->orderBy('title')
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

    public function clear(): void
    {
        $this->reset('query', 'console', 'status');
        $this->resetPage();
    }
}; ?>

<section class="w-full">
    <div class="flex flex-col gap-6">
        <div class="min-w-0">
            <p class="kicker mb-1.5 text-fg-faint">{{ __('Library') }}</p>
            <h1 class="text-display font-medium tracking-display text-fg-bright">{{ __('Games') }}</h1>
        </div>

        <div class="flex flex-wrap items-end gap-3">
            <flux:input wire:model.live.debounce.300ms="query" :placeholder="__('Search titles')" class="min-w-56 flex-1" size="sm" />

            <flux:select wire:model.live="console" size="sm" class="w-44">
                <flux:select.option value="">{{ __('All consoles') }}</flux:select.option>
                @foreach ($this->consoles as $option)
                    <flux:select.option value="{{ $option->key }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="status" size="sm" class="w-44">
                <flux:select.option value="">{{ __('Any status') }}</flux:select.option>
                @foreach (GameStatus::cases() as $case)
                    <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($query !== '' || $console !== '' || $status !== '')
                <flux:button size="sm" variant="ghost" wire:click="clear">{{ __('Clear') }}</flux:button>
            @endif
        </div>

        @if ($this->games->isEmpty())
            <div class="rounded-xl border border-dashed border-line-input px-6 py-10 text-center">
                <p class="text-sm text-fg-soft">{{ __('Nothing matches that.') }}</p>
                <p class="mt-1 text-sm text-fg-faint">{{ __('Scan a console to fill the library.') }}</p>
            </div>
        @else
            <div class="overflow-hidden rounded-xl border border-line">
                <table class="w-full text-sm">
                    <thead class="bg-sunken text-left text-fg-faint">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">{{ __('Title') }}</th>
                            <th class="px-4 py-2.5 font-medium">{{ __('Console') }}</th>
                            <th class="px-4 py-2.5 font-medium">{{ __('Files') }}</th>
                            <th class="px-4 py-2.5 font-medium">{{ __('Status') }}</th>
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
                                <td class="px-4 py-2.5 text-fg-soft">{{ $game->console()?->name ?? $game->console }}</td>
                                <td class="px-4 py-2.5 text-fg-soft">{{ $game->files_count }}</td>
                                <td class="px-4 py-2.5">
                                    <flux:badge size="sm" :color="match ($game->status) {
                                        App\Enums\GameStatus::Matched => 'green',
                                        App\Enums\GameStatus::Unmatched => 'amber',
                                        default => 'zinc',
                                    }">{{ $game->status->label() }}</flux:badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $this->games->links() }}
        @endif
    </div>
</section>
