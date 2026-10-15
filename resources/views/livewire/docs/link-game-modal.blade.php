<?php

use App\Enums\MediaKind;
use App\Models\Game;
use App\Services\DocLinks;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Link the open doc to a game, found by title, from the Docs page's Games row.
 */
new class extends Component
{
    public const MODAL = 'link-game';

    /** Games listed at once; a longer title narrows it. */
    public const LIMIT = 8;

    #[Locked]
    public string $path = '';

    public string $search = '';

    /**
     * The best matches not linked to this doc yet.
     *
     * @return Collection<int, Game>
     */
    #[Computed]
    public function matches(): Collection
    {
        if (mb_strlen(trim($this->search)) < 2) {
            return collect();
        }

        $linked = app(DocLinks::class)->gamesFor($this->path)->pluck('id');

        return Game::query()
            ->search(trim($this->search))
            ->orderBy('games.title')
            ->whereNotIn('id', $linked)
            ->select(['id', 'console', 'slug', 'title'])
            ->limit(self::LIMIT)
            ->with(['media' => function ($query): void {
                $query->ofKind(MediaKind::Cover);
            }])
            ->get();
    }

    public function link(int $gameId, DocLinks $links): void
    {
        $game = Game::query()->find($gameId);

        if ($game === null) {
            return;
        }

        $links->link($this->path, $game);
        $this->reset('search');

        Flux::modal(self::MODAL)->close();
        Flux::toast(variant: 'success', text: __('Linked to :title.', ['title' => $game->title]));

        $this->dispatch('doc-linked');
    }
}; ?>

<div>
    <flux:modal.trigger :name="$this::MODAL">
        <x-chip dashed>{{ __('+ Link game') }}</x-chip>
    </flux:modal.trigger>

    <x-search-modal
        :name="$this::MODAL"
        :heading="__('Link a game')"
        :placeholder="__('Search games by title')"
        :has-results="$this->matches->isNotEmpty()"
        :empty="mb_strlen(trim($search)) < 2 ? __('Type at least two letters of a title.') : __('No game matches that.')"
    >
        @foreach ($this->matches as $game)
            <x-search-row
                :label="$game->title"
                :detail="$game->console()?->name"
                :image="$game->listThumbnail()"
                wire:key="link-game-{{ $game->id }}"
                wire:click="link({{ $game->id }})"
            />
        @endforeach
    </x-search-modal>
</div>
