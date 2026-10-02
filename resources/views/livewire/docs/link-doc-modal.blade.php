<?php

use App\Models\Game;
use App\Resources\DocResource;
use App\Services\DocLibrary;
use App\Services\DocLinks;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Link a doc already written to this game, from the game page's Actions menu.
 * Opened by name; the page draws no trigger of its own for it.
 */
new class extends Component
{
    public const MODAL = 'link-doc';

    public const LIMIT = 8;

    #[Locked]
    public int $gameId = 0;

    public string $search = '';

    /**
     * Docs matching the search — all of them, newest first, before anything is
     * typed — less the ones this game has already.
     *
     * @return Collection<int, DocResource>
     */
    #[Computed]
    public function matches(): Collection
    {
        $game = Game::query()->find($this->gameId);

        if ($game === null) {
            return collect();
        }

        $linked = app(DocLinks::class)->docsFor($game)->pluck('path');

        return app(DocLibrary::class)
            ->search(trim($this->search))
            ->reject(function (DocResource $doc) use ($linked): bool {
                return $linked->contains($doc->path);
            })
            ->take(self::LIMIT)
            ->values();
    }

    public function link(string $path, DocLinks $links): void
    {
        $game = Game::query()->find($this->gameId);

        if ($game === null || app(DocLibrary::class)->find($path) === null) {
            return;
        }

        $links->link($path, $game);
        $this->reset('search');

        Flux::modal(self::MODAL)->close();
        Flux::toast(variant: 'success', text: __('Doc linked.'));

        $this->dispatch('doc-linked', path: $path);
    }
}; ?>

<div>
    <flux:modal :name="$this::MODAL" class="w-full max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Link a doc') }}</flux:heading>

            <flux:input wire:model.live.debounce.200ms="search" icon="magnifying-glass" :placeholder="__('Search docs')" autofocus />

            <div class="max-h-80 overflow-y-auto">
                @forelse ($this->matches as $doc)
                    <x-search-row
                        :label="$doc->title"
                        :detail="$doc->subtitle()"
                        icon="document-text"
                        wire:key="link-doc-{{ md5($doc->path) }}"
                        wire:click="link(@js($doc->path))"
                    />
                @empty
                    <p class="px-2 py-6 text-center text-sm text-fg-faint">
                        {{ trim($search) === '' ? __('No docs left to link. Write one from the Actions menu.') : __('No doc matches that.') }}
                    </p>
                @endforelse
            </div>
        </div>
    </flux:modal>
</div>
