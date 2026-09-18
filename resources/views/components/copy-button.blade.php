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
])

@php
    $variants = [
        'plain' => 'rounded px-2 py-1 text-sm text-fg-faint hover:bg-hover hover:text-fg-soft',
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
    {{ $attributes->class([
        'shrink-0 cursor-pointer transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep',
        \Illuminate\Support\Arr::get($variants, $variant, \Illuminate\Support\Arr::get($variants, 'plain')),
    ]) }}
>
    {{ $slot }}
    <span x-text="copied ? @js(__('Copied')) : @js($label ?? __('Copy'))"></span>
</button>
