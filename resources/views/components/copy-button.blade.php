{{--
    Copies a value, then flashes confirmation.

    The execCommand fallback is not legacy cruft: this app is reached over
    plain HTTP on a LAN, where navigator.clipboard is unavailable and fails
    silently. Carried over from the previous build's CopyButton.
--}}
@props([
    'text',
    'label' => null,
    // `menu` is a dropdown row; `plain` a bare control beside a value.
    'variant' => 'plain',
    // Just the copy icon, the label kept for a screen reader.
    'icon' => false,
])

@php
    $variants = [
        // Inline-flex so an icon in the slot and the label sit on one line.
        'plain' => 'inline-flex items-center gap-1 whitespace-nowrap rounded px-2 py-1 text-sm text-fg-faint hover:bg-hover hover:text-fg-soft',
        'menu' => 'flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm text-fg-soft hover:bg-raised',
    ];
@endphp

<button
    type="button"
    x-data="{ copied: false }"
    x-on:click="
        const value = @js($text);
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
            const field = document.createElement('textarea');
            field.value = value;
            field.setAttribute('readonly', '');
            field.style.position = 'fixed';
            field.style.opacity = '0';
            document.body.appendChild(field);
            field.select();
            done = document.execCommand('copy');
            document.body.removeChild(field);
        }

        if (done) {
            copied = true;
            setTimeout(() => copied = false, 1500);
        }
    "
    @if ($icon)
        aria-label="{{ $label ?: __('Copy') }}"
    @endif
    {{ $attributes->class([
        'shrink-0 cursor-pointer transition-colors focus-ring',
        \Illuminate\Support\Arr::get($variants, $variant, \Illuminate\Support\Arr::get($variants, 'plain')),
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
