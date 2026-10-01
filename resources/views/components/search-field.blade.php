@props(['placeholder' => __('Search titles')])

{{-- Hand-written rather than flux:input, for the reason the Actions button
     beside it is: it sits on key art and wears the bar's glass, which Flux's
     own input classes would fight. Everything but class lands on the input,
     so wire:model goes where it is needed.

     / focuses it from anywhere on the page, unless something else is being
     typed in; Ctrl+K belongs to the global search. Only one of these is ever
     drawn at a time, so the window listener has no rival. With text in it,
     the hint gives way to a button that empties it. --}}
<label {{ $attributes->only('class')->class('relative flex min-w-0 items-center') }} x-data="{ filled: false }">
    <flux:icon.magnifying-glass class="pointer-events-none absolute left-2.75 size-3.5 text-fg-dim" />

    <input
        type="search"
        placeholder="{{ $placeholder }}"
        aria-label="{{ $placeholder }}"
        aria-keyshortcuts="/"
        x-ref="input"
        x-init="$nextTick(() => filled = $el.value !== '')"
        x-on:input="filled = $el.value !== ''"
        x-on:keydown.slash.window="if (! $event.target.closest('input, textarea, select, [contenteditable]')) { $event.preventDefault(); $el.focus(); $el.select() }"
        x-on:keydown.escape="$el.blur()"
        {{ $attributes->except('class') }}
        class="peer w-full rounded-lg border border-line-input bg-scrim/60 py-1.5 pr-10 pl-8 text-sm text-fg-soft backdrop-blur-sm transition-colors placeholder:text-fg-dim hover:border-line-bright focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep [&::-webkit-search-cancel-button]:hidden"
    />

    {{-- Hidden while typing, where it would sit on top of the text. --}}
    <kbd aria-hidden="true" x-show="! filled" class="pointer-events-none absolute right-2.75 hidden font-sans text-xs text-fg-dim peer-focus:hidden sm:block">/</kbd>

    {{-- An input event, as typing sends, so wire:model hears it empty. --}}
    <button
        type="button"
        x-show="filled"
        x-cloak
        x-on:click.prevent="$refs.input.value = ''; $refs.input.dispatchEvent(new Event('input', { bubbles: true })); $refs.input.focus()"
        aria-label="{{ __('Clear search') }}"
        title="{{ __('Clear search') }}"
        class="absolute right-1.5 grid size-6 cursor-pointer place-items-center rounded-md text-fg-dim transition-colors hover:bg-hover hover:text-fg-soft"
    >
        <flux:icon.x-mark variant="micro" class="size-3.5" />
    </button>
</label>
