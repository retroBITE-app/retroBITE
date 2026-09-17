@php($doc = $this->current())

@if ($doc === null)
    <div class="px-6 py-16 text-center">
        <flux:icon.book-open class="mx-auto mb-3 size-8 text-fg-faint" />
        <p class="text-sm text-fg-soft">
            {{ $this->docs->isEmpty() && $query === '' && $filter === ''
                ? __('Repairs, mods and setup notes, written as markdown files on disk.')
                : __('Nothing matches that.') }}
        </p>
        <p class="mt-1 text-sm text-fg-faint">
            {{ $this->docs->isEmpty() && $query === '' && $filter === ''
                ? __('Create the first one to get started.')
                : __('Try a different search or filter.') }}
        </p>
    </div>
@else
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4">
        <div class="min-w-0">
            <h2 class="truncate text-base font-medium text-fg-bright">
                @if ($doc->console !== '')
                    <span class="font-mono text-sm text-fg-dim">{{ Str::upper($doc->console) }}</span>
                @endif
                {{ $doc->title }}
            </h2>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            @unless ($editing)
                <div class="flex items-center gap-0.5 rounded-lg border border-line-input p-0.5">
                    @foreach (['preview' => __('Preview'), 'markdown' => __('Markdown')] as $value => $label)
                        <button
                            type="button"
                            wire:click="$set('mode', '{{ $value }}')"
                            @class([
                                'cursor-pointer rounded-md px-2.75 py-1 text-xs transition-colors',
                                'bg-accent-tint/15 text-accent' => $mode === $value,
                                'text-fg-dim hover:bg-hover hover:text-fg' => $mode !== $value,
                            ])
                        >{{ $label }}</button>
                    @endforeach
                </div>

                <flux:button size="sm" icon="pencil-square" wire:click="edit">{{ __('Edit') }}</flux:button>
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
        </div>
    </div>

    @if ($doc->tags !== [])
        <div class="flex flex-wrap gap-1.5 border-b border-line px-5 py-3">
            @foreach ($doc->tags as $tag)
                <span
                    wire:key="tag-{{ $loop->index }}"
                    class="rounded-md border border-line-input bg-sunken px-2 py-0.5 font-mono text-xs text-fg-dim"
                >{{ $tag }}</span>
            @endforeach
        </div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-2.5">
        <p class="min-w-0 truncate font-mono text-xs text-fg-faint">
            {{ $doc->path }}
            · {{ $doc->humanSize() }}
            · {{ __('edited :when', ['when' => $doc->updatedAt->diffForHumans(short: true)]) }}
        </p>

        <div class="flex shrink-0 items-center gap-2">
            @if ($editing)
                <livewire:docs.media-modal :path="$doc->path" :key="'media-'.$doc->path" />
                <livewire:docs.reference-modal :path="$doc->path" :body="$draft" :key="'reference-'.$doc->path" />
            @endif

            <p class="font-mono text-xs text-fg-faint">
                {{ $this->mediaDirectory }} · {{ trans_choice(':count attached|:count attached', count($doc->attachments()), ['count' => count($doc->attachments())]) }}
            </p>
        </div>
    </div>

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
@endif
