<?php

use App\Enums\MediaKind;
use App\Jobs\MatchGame;
use App\Jobs\ScrapeGameMedia;
use App\Models\Game;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Game')] class extends Component
{
    public Game $game;

    public function mount(Game $game): void
    {
        $this->game = $game->load(['files' => fn ($q) => $q->orderByRaw('disc_number IS NULL, disc_number')->orderBy('id'), 'media']);
    }

    /**
     * What the game looked like when a lookup was queued, or null when idle.
     *
     * The poll is tied to this so the page stops asking as soon as an answer
     * lands. Deliberately not updated_at on its own: that column holds seconds,
     * so two changes inside one second are indistinguishable.
     */
    public ?string $awaiting = null;

    /** When the wait began, so a lookup that answers nothing still ends it. */
    public ?int $awaitingSince = null;

    /**
     * How long to keep asking.
     *
     * A retry on a game the provider still cannot name changes nothing at all,
     * so there is no answer to wait for — only a queue that has got to it.
     */
    private const WAIT_SECONDS = 120;

    public function identify(): void
    {
        if ($reason = $this->game->blockedFromLookup()) {
            Flux::toast(variant: 'warning', text: $reason);

            return;
        }

        $this->awaiting = $this->fingerprint();
        $this->awaitingSince = now()->timestamp;

        MatchGame::dispatch($this->game->id);

        Flux::toast(text: __('Identifying :title.', ['title' => $this->game->title]));
    }

    /** How many media the game had when a fetch was queued, or null when idle. */
    public ?int $fetchingFrom = null;

    public ?int $fetchingSince = null;

    public function fetchMedia(): void
    {
        if ($reason = $this->game->blockedFromMediaScrape()) {
            Flux::toast(variant: 'warning', text: $reason);

            return;
        }

        // Counted rather than fingerprinted: artwork arrives as new rows and
        // leaves the game itself untouched.
        $this->fetchingFrom = $this->game->media()->count();
        $this->fetchingSince = now()->timestamp;

        ScrapeGameMedia::dispatch($this->game->id);

        Flux::toast(text: __('Fetching artwork for :title.', ['title' => $this->game->title]));
    }

    /** Called by the poll while a fetch is outstanding. */
    public function checkMedia(): void
    {
        if ($this->game->media()->count() !== $this->fetchingFrom) {
            $this->fetchingFrom = null;
            $this->fetchingSince = null;
            $this->game->load('media');
            unset($this->cover);

            return;
        }

        // A game whose artwork the provider does not hold adds nothing, so
        // there is no arrival to notice.
        if ($this->fetchingSince !== null && now()->timestamp - $this->fetchingSince >= self::WAIT_SECONDS) {
            $this->fetchingFrom = null;
            $this->fetchingSince = null;
        }
    }

    /** Called by the poll while a lookup is outstanding. */
    public function checkAnswer(): void
    {
        $this->game->refresh();

        if ($this->fingerprint() !== $this->awaiting) {
            $this->stopWaiting();
            $this->game->load(['files', 'media']);

            return;
        }

        if ($this->awaitingSince !== null && now()->timestamp - $this->awaitingSince >= self::WAIT_SECONDS) {
            $this->stopWaiting();
        }
    }

    private function stopWaiting(): void
    {
        $this->awaiting = null;
        $this->awaitingSince = null;
    }

    /** Everything a lookup can change about the game itself. */
    private function fingerprint(): string
    {
        return implode('|', [
            $this->game->status->value,
            (string) $this->game->screenscraper_id,
            (string) $this->game->title,
            (string) $this->game->updated_at?->getTimestamp(),
        ]);
    }

    /** The artwork to lead with, if any has been fetched. */
    #[Computed]
    public function cover(): ?string
    {
        $media = $this->game->media()->ofKind(MediaKind::Cover)->first();

        return $media?->path;
    }

    /** @return Collection<int, \App\Models\GameFile> */
    #[Computed]
    public function files(): Collection
    {
        return $this->game->files;
    }
}; ?>

<section class="w-full">
    <div class="flex flex-col gap-6">
        <div>
            <a href="{{ route('games.index') }}" wire:navigate class="kicker text-fg-faint hover:text-accent">
                {{ __('Library') }}
            </a>
            <h1 class="mt-1.5 text-display font-medium tracking-display text-fg-bright">{{ $game->title }}</h1>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm text-fg-soft">
                <span>{{ $game->console()?->name ?? $game->console }}</span>
                @if ($game->release_date)
                    <span class="text-fg-faint">·</span><span>{{ $game->release_date }}</span>
                @endif
                @if ($game->publisher)
                    <span class="text-fg-faint">·</span><span>{{ $game->publisher }}</span>
                @endif
                <flux:badge size="sm" :color="match ($game->status) {
                    App\Enums\GameStatus::Matched => 'green',
                    App\Enums\GameStatus::Unmatched => 'amber',
                    default => 'zinc',
                }">{{ $game->status->label() }}</flux:badge>
            </div>
        </div>

        @if ($awaiting !== null)
            <div wire:poll.3s="checkAnswer"
                 class="flex items-center gap-3 rounded-xl border border-accent-tint bg-accent-tint px-5 py-3">
                <flux:icon.arrow-path class="size-4 animate-spin text-accent" />
                <p class="text-sm text-fg">{{ __('Waiting for ScreenScraper…') }}</p>
            </div>
        @elseif ($reason = $game->blockedFromLookup())
            @unless ($game->status === App\Enums\GameStatus::Matched)
                {{-- Say what is wrong rather than offer a button that does nothing. --}}
                <p class="rounded-xl border border-dashed border-line-input px-5 py-3 text-sm text-fg-faint">
                    {{ $reason }}
                </p>
            @endunless
        @else
            <div>
                <flux:button size="sm" variant="primary" icon="sparkles" wire:click="identify">
                    {{ $game->status === App\Enums\GameStatus::Unmatched ? __('Try identifying again') : __('Identify') }}
                </flux:button>
            </div>
        @endif

        @if ($fetchingFrom !== null)
            <div wire:poll.3s="checkMedia"
                 class="flex items-center gap-3 rounded-xl border border-accent-tint bg-accent-tint px-5 py-3">
                <flux:icon.arrow-path class="size-4 animate-spin text-accent" />
                <p class="text-sm text-fg">{{ __('Fetching artwork…') }}</p>
            </div>
        @elseif ($game->canFetchMedia())
            <div>
                <flux:button size="sm" variant="ghost" icon="photo" wire:click="fetchMedia">
                    {{ $game->media->isEmpty() ? __('Fetch artwork') : __('Fetch artwork again') }}
                </flux:button>
            </div>
        @elseif ($game->status === App\Enums\GameStatus::Matched)
            {{-- Identified, so the only thing standing in the way is the settings. --}}
            <p class="rounded-xl border border-dashed border-line-input px-5 py-3 text-sm text-fg-faint">
                {{ $game->blockedFromMediaScrape() }}
            </p>
        @endif

        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
            <div class="flex items-center justify-center overflow-hidden rounded-xl border border-line bg-sunken p-6">
                @if ($this->cover)
                    <img src="{{ route('media.show', ['path' => $this->cover]) }}" alt="" class="max-h-72 rounded-lg object-contain" />
                @else
                    <div class="flex flex-col items-center gap-2 py-10 text-fg-faint">
                        <flux:icon.photo class="size-8" />
                        <p class="text-sm">{{ __('No artwork yet') }}</p>
                    </div>
                @endif
            </div>

            <div class="flex flex-col gap-4">
                @if ($game->description)
                    <p class="text-sm leading-relaxed text-fg-soft">{{ $game->description }}</p>
                @endif

                <div class="overflow-hidden rounded-xl border border-line">
                    <table class="w-full text-sm">
                        <thead class="bg-sunken text-left text-fg-faint">
                            <tr>
                                <th class="px-4 py-2.5 font-medium">{{ __('File') }}</th>
                                <th class="px-4 py-2.5 font-medium">{{ __('Role') }}</th>
                                <th class="px-4 py-2.5 font-medium">{{ __('Disc') }}</th>
                                <th class="px-4 py-2.5 font-medium">{{ __('Size') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->files as $file)
                                <tr wire:key="file-{{ $file->id }}" @class([
                                    'border-t border-line',
                                    'opacity-50' => ! $file->isPresent(),
                                ])>
                                    <td class="px-4 py-2.5">
                                        <span class="font-mono text-xs text-fg-bright">{{ $file->filename }}</span>
                                        @unless ($file->isPresent())
                                            {{-- Kept rather than deleted: usually an unmounted disk, and
                                                 throwing the row away would mean identifying it again. --}}
                                            <flux:badge size="sm" color="amber" class="ml-2">{{ __('Missing') }}</flux:badge>
                                        @endunless
                                    </td>
                                    <td class="px-4 py-2.5 text-fg-soft">{{ $file->role->label() }}</td>
                                    <td class="px-4 py-2.5 text-fg-soft">{{ $file->disc_number ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-fg-soft">
                                        {{ $file->size_bytes ? \Illuminate\Support\Number::fileSize($file->size_bytes, 1) : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($game->media->isNotEmpty())
                    <div class="flex flex-wrap gap-2">
                        @foreach ($game->media as $media)
                            <img src="{{ route('media.show', ['path' => $media->path]) }}" alt="{{ $media->screenscraper_type }}"
                                 class="h-20 rounded-lg border border-line object-contain" />
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</section>
