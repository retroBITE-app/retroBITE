{{--
    Copies a value, then flashes confirmation.

    The execCommand fallback is not legacy cruft: this app is reached over
    plain HTTP on a LAN, where navigator.clipboard is unavailable and fails
    silently. Carried over from the previous build's CopyButton.
--}}
@props([
    'text',
    'label' => null,
    // `menu` is a flux:menu.item, for a row inside a flux:menu, so it takes
    // the menu's look, hover and keyboard handling; `plain` a bare control
    // beside a value.
    'variant' => 'plain',
    // Just the copy icon, the label kept for a screen reader.
    'icon' => false,
])

@php
    $copy = 'const value = '.Illuminate\Support\Js::from($text).';
        let done = false;

        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(value);
                done = true;
            }
        } catch (error) {
            // Fall through to the legacy path rather than failing silently.
        }

        if (! done) {
            const field = document.createElement(\'textarea\');
            field.value = value;
            field.setAttribute(\'readonly\', \'\');
            field.style.position = \'fixed\';
            field.style.opacity = \'0\';
            document.body.appendChild(field);
            field.select();
            done = document.execCommand(\'copy\');
            document.body.removeChild(field);
        }

        if (done) {
            copied = true;
            setTimeout(() => copied = false, 1500);
        }';

    $behaviour = ['x-data' => '{ copied: false }', 'x-on:click' => $copy];
@endphp

@if ($variant === 'menu')
    <flux:menu.item icon="document-duplicate" :attributes="$attributes->merge($behaviour)">
        {{ $slot }}
        <span x-text="copied ? @js(__('Copied')) : @js($label ?? __('Copy'))"></span>
    </flux:menu.item>
@else
    <button
        type="button"
        @if ($icon)
            aria-label="{{ $label ?: __('Copy') }}"
        @endif
        {{ $attributes->merge($behaviour)->class([
            // Inline-flex so an icon in the slot and the label sit on one line.
            'inline-flex shrink-0 cursor-pointer items-center gap-1 whitespace-nowrap rounded px-2 py-1 text-sm text-fg-faint transition-colors hover:bg-hover hover:text-fg-soft focus-ring',
        ]) }}
    >
        @if ($icon)
            <flux:icon.document-duplicate class="size-3.5 text-fg-faint" />
            {{-- Says so only once it has copied, as the label="" callers did. --}}
            <span x-text="copied ? @js(__('Copied')) : ''"></span>
        @else
            {{ $slot }}
            <span x-text="copied ? @js(__('Copied')) : @js($label ?? __('Copy'))"></span>
        @endif
    </button>
@endif
