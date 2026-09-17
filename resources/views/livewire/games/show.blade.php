<?php

use App\Enums\MediaKind;
use App\Models\Game;
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
