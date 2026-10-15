{{-- The open document, inside docs/index's panel. $doc is set there, and
     with none open the empty state stands in the panel's place. --}}
<x-panel.header>
    <div class="min-w-0">
        <h2 class="truncate text-base font-medium text-fg-bright">
            @if ($doc->console !== '')
                <span class="font-mono text-sm text-fg-dim">{{ Str::upper($doc->console) }}</span>
            @endif
            {{ $doc->title }}
        </h2>
    </div>

    <x-slot:actions>
        @unless ($editing)
            <x-pill-choice
                :options="['preview' => __('Preview'), 'markdown' => __('Markdown')]"
                :label="__('Show the document as')"
                model="mode"
                :value="$mode"
            />

            {{-- Every saved version, newest first, to read and put back. --}}
            @if ($this->history->isNotEmpty())
                <flux:dropdown position="bottom" align="end">
                    <flux:button size="sm" variant="ghost" icon="clock">{{ __('History') }}</flux:button>

                    <flux:menu class="max-h-80 overflow-y-auto">
                        <flux:menu.item wire:click="viewRevision(0)" :icon="$revision === 0 ? 'check' : null">
                            {{ __('Current version') }}
                        </flux:menu.item>
                        <flux:menu.separator />
                        @foreach ($this->history as $timestamp)
                            @php($at = Illuminate\Support\Carbon::createFromTimestampMs($timestamp)->setTimezone(config('app.timezone')))
                            <flux:menu.item wire:key="rev-{{ $timestamp }}" wire:click="viewRevision({{ $timestamp }})" :icon="$revision === $timestamp ? 'check' : null">
                                <span>{{ $at->isoFormat('D MMM YYYY, HH:mm') }}</span>
                                <span class="ml-2 text-xs text-fg-faint">{{ $at->diffForHumans() }}</span>
                            </flux:menu.item>
                        @endforeach
                    </flux:menu>
                </flux:dropdown>
            @endif

            @if ($revision === 0)
                <flux:button size="sm" icon="pencil-square" wire:click="edit">{{ __('Edit') }}</flux:button>
            @endif
        @else
            <flux:button size="sm" variant="ghost" wire:click="cancel">{{ __('Cancel') }}</flux:button>
            <flux:button size="sm" variant="primary" wire:click="save">{{ __('Save') }}</flux:button>
        @endunless

        <flux:dropdown position="bottom" align="end">
            <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('More actions')" />

            <flux:menu>
                <flux:menu.item icon="arrow-down-tray" href="{{ route('docs.download', ['path' => $doc->path]) }}">
                    {{ __('Download .md') }}
                </flux:menu.item>
                @if (app(App\Services\DocArchive::class)->available())
                    <flux:menu.item icon="archive-box-arrow-down" href="{{ route('docs.archive', ['path' => $doc->path]) }}">
                        {{ __('Download with media') }}
                    </flux:menu.item>
                @endif
                <flux:menu.separator />
                <flux:menu.item
                    icon="trash"
                    variant="danger"
                    wire:click="delete"
                    wire:confirm="{{ __('Delete this document, its history, and any images only it uses?') }}"
                >
                    {{ __('Delete') }}
                </flux:menu.item>
            </flux:menu>
        </flux:dropdown>
    </x-slot:actions>
</x-panel.header>

{{-- An earlier version, read-only until it is put back. --}}
@if ($revision !== 0)
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-accent-tint/30 bg-accent-tint/8 px-5 py-3">
        <p class="text-sm text-fg-soft">
            {{ __('Viewing the version from :date.', ['date' => Illuminate\Support\Carbon::createFromTimestampMs($revision)->setTimezone(config('app.timezone'))->isoFormat('D MMM YYYY, HH:mm')]) }}
        </p>

        <div class="flex items-center gap-2">
            <flux:button size="sm" variant="ghost" wire:click="viewRevision(0)">{{ __('Back to current') }}</flux:button>
            <flux:button size="sm" variant="primary" icon="arrow-uturn-left" wire:click="restoreRevision"
                         wire:confirm="{{ __('Make this version the current one? The current one is kept in the history.') }}">
                {{ __('Restore this version') }}
            </flux:button>
        </div>
    </div>
@endif

@if ($doc->tags !== [])
    <x-panel.header class="gap-1.5!">
        @foreach ($doc->tags as $tag)
            <x-badge size="sm" tone="muted" fill wire:key="tag-{{ $loop->index }}">{{ $tag }}</x-badge>
        @endforeach
    </x-panel.header>
@endif

{{-- The games this doc is about; each one's page shows it under Docs. --}}
@if ($revision === 0)
    <x-panel.header class="gap-1.5!">
        <span class="kicker mr-1 text-fg-faint">{{ __('Games') }}</span>

        @foreach ($this->linkedGames as $game)
            <span wire:key="linked-game-{{ $game->id }}" class="flex h-7 items-center gap-1.5 rounded-md border border-line-input bg-sunken pr-1 pl-2.5 text-xs text-fg-soft">
                <a href="{{ route('games.show', $game->routeParameters()) }}" wire:navigate class="hover:text-accent">{{ $game->title }}</a>
                <x-icon-button
                    size="sm"
                    icon="x-mark"
                    :label="__('Unlink :title', ['title' => $game->title])"
                    wire:click="unlinkGame({{ $game->id }})"
                />
            </span>
        @endforeach

        <livewire:docs.link-game-modal :path="$doc->path" wire:key="link-game-{{ $doc->path }}" />
    </x-panel.header>
@endif

<x-panel.header class="py-2.5!">
    <p class="min-w-0 truncate font-mono text-xs text-fg-faint">
        {{ $doc->path }}
        · {{ $doc->humanSize() }}
        · {{ __('edited :when', ['when' => $doc->updatedAt->diffForHumans(short: true)]) }}
    </p>

    <x-slot:actions>
        @if ($editing)
            <livewire:docs.media-modal :path="$doc->path" :key="'media-'.$doc->path" />
            <livewire:docs.reference-modal :path="$doc->path" :body="$draft" :key="'reference-'.$doc->path" />
        @endif

        <p class="font-mono text-xs text-fg-faint">
            {{ $this->mediaDirectory }} · {{ trans_choice(':count attached|:count attached', count($doc->attachments()), ['count' => count($doc->attachments())]) }}
        </p>
    </x-slot:actions>
</x-panel.header>

<div class="px-5 py-5">
    @if ($editing)
        <textarea
            wire:model="draft"
            x-data
            x-on:doc-insert-at-cursor.window="
                const start = $el.selectionStart, end = $el.selectionEnd;
                $el.value = $el.value.slice(0, start) + $event.detail.snippet + $el.value.slice(end);
                $el.dispatchEvent(new Event('input'));
                $el.focus();
                $el.selectionStart = $el.selectionEnd = start + $event.detail.snippet.length;
            "
            rows="28"
            spellcheck="false"
            class="w-full resize-y rounded-lg border border-line-input bg-sunken px-4 py-3 font-mono text-xs leading-relaxed text-fg outline-hidden focus:ring-2 focus:ring-accent-deep focus:ring-offset-2 focus:ring-offset-ground"
        ></textarea>
    @elseif ($mode === 'markdown')
        <pre class="overflow-x-auto rounded-lg border border-line bg-sunken px-4 py-3 font-mono text-xs leading-relaxed text-fg-soft">{{ $doc->body }}</pre>
    @else
        @include('livewire.docs.partials.preview')
    @endif
</div>
