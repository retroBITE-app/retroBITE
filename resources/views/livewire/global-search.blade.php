<?php

use App\Services\GlobalSearch;
use Livewire\Component;

/**
 * The Ctrl+K box: games, consoles, pages and settings, from any page.
 *
 * Mounted once in the layout and persisted across wire:navigate, so it opens
 * at once wherever it is asked for. What it finds is GlobalSearch's; the
 * keyboard — the shortcut, the arrows, Enter — is the globalSearch Alpine
 * module's, since the CSP keeps that logic out of the attributes.
 */
new class extends Component
{
    public string $term = '';

    /** @return array<string, mixed> */
    public function with(GlobalSearch $search): array
    {
        return ['groups' => $search->results($this->term)];
    }

    /** Closed: the next open starts from the quick-jump list. */
    public function clear(): void
    {
        $this->term = '';
    }
}; ?>

<div
    x-data="globalSearch"
    x-on:keydown.ctrl.k.window.prevent="open()"
    x-on:keydown.meta.k.window.prevent="open()"
    x-on:open-global-search.window="open()"
>
    <flux:modal name="global-search" variant="bare" wire:close="clear" class="w-full max-w-xl">
        <div class="overflow-hidden rounded-xl border border-line-strong bg-surface shadow-lift">
            <label class="flex items-center gap-3 border-b border-line px-4 py-3.5">
                <flux:icon.magnifying-glass class="size-5 shrink-0 text-fg-dim" />
                <input
                    type="search"
                    data-search-input
                    autofocus
                    wire:model.live.debounce.200ms="term"
                    x-on:input="reset()"
                    x-on:keydown.arrow-down.prevent="move(1)"
                    x-on:keydown.arrow-up.prevent="move(-1)"
                    x-on:keydown.enter.prevent="choose()"
                    placeholder="{{ __('Search games, consoles, pages and settings') }}"
                    aria-label="{{ __('Search games, consoles, pages and settings') }}"
                    autocomplete="off"
                    spellcheck="false"
                    class="w-full bg-transparent text-base text-fg-bright outline-none placeholder:text-fg-dim [&::-webkit-search-cancel-button]:hidden"
                />
                <flux:icon.loading wire:loading wire:target="term" class="size-4 shrink-0 text-fg-dim" />
            </label>

            <div class="max-h-[60vh] overflow-y-auto p-2">
                @php($index = 0)

                @forelse ($groups as ['key' => $key, 'label' => $label, 'items' => $items])
                    <div wire:key="group-{{ $key }}" class="mb-1 last:mb-0">
                        <p class="kicker px-2 pt-2 pb-1.5 text-fg-faint">{{ $label }}</p>

                        @foreach ($items as ['label' => $itemLabel, 'detail' => $detail, 'url' => $url, 'icon' => $icon, 'image' => $image])
                            <x-search-row
                                :href="$url"
                                :label="$itemLabel"
                                :detail="$detail"
                                :image="$image"
                                :icon="$icon"
                                wire:navigate
                                wire:key="item-{{ $key }}-{{ $loop->index }}"
                                data-search-item
                                x-bind:data-active="active === {{ $index }}"
                                x-on:mouseenter="active = {{ $index }}"
                                x-on:click="close()"
                                class="data-[active=true]:bg-accent-tint/13 data-[active=true]:text-accent"
                            />

                            @php($index++)
                        @endforeach
                    </div>
                @empty
                    <p class="px-2 py-8 text-center text-sm text-fg-faint">{{ __('Nothing matches “:term”.', ['term' => trim($term)]) }}</p>
                @endforelse
            </div>

            <div class="flex items-center justify-end gap-4 border-t border-line px-4 py-2.5 text-xs text-fg-faint">
                <span><kbd class="font-sans text-fg-dim">↑↓</kbd> {{ __('move') }}</span>
                <span><kbd class="font-sans text-fg-dim">↵</kbd> {{ __('open') }}</span>
                <span><kbd class="font-sans text-fg-dim">esc</kbd> {{ __('close') }}</span>
            </div>
        </div>
    </flux:modal>
</div>
