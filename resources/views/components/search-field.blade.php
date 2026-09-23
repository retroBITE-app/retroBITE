@props(['placeholder' => __('Search titles')])

{{-- Hand-written rather than flux:input, for the reason the Actions button
     beside it is: it sits on key art and wears the bar's glass, which Flux's
     own input classes would fight. Everything but class lands on the input,
     so wire:model goes where it is needed.

     Ctrl+K (Cmd+K on a Mac) focuses it from anywhere on the page. Only one of
     these is ever drawn at a time, so the window listener has no rival. --}}
<label {{ $attributes->only('class')->class('relative flex min-w-0 items-center') }}>
    <flux:icon.magnifying-glass class="pointer-events-none absolute left-2.75 size-3.5 text-fg-dim" />

    <input
        type="search"
        placeholder="{{ $placeholder }}"
        aria-label="{{ $placeholder }}"
        aria-keyshortcuts="Control+K Meta+K"
        x-data
        x-on:keydown.ctrl.k.window.prevent="$el.focus(); $el.select()"
        x-on:keydown.meta.k.window.prevent="$el.focus(); $el.select()"
        x-on:keydown.escape="$el.blur()"
        {{ $attributes->except('class') }}
        class="peer w-full rounded-lg border border-line-input bg-scrim/60 py-1.5 pr-10 pl-8 text-sm text-fg-soft backdrop-blur-sm transition-colors placeholder:text-fg-dim hover:border-line-bright focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep [&::-webkit-search-cancel-button]:hidden"
    />

    {{-- Hidden while typing, where it would sit on top of the text. --}}
    <kbd aria-hidden="true" class="pointer-events-none absolute right-2.75 hidden font-sans text-xs text-fg-dim peer-focus:hidden sm:block">⌘K</kbd>
</label>
