{{--
    One result in a search list: a thumbnail or icon, the title, and a detail
    on the right. The Ctrl+K box and the doc ↔ game link dialogs draw their
    rows with it, so a result looks the same wherever it is picked from.

    A link when given an href, a button otherwise. Everything else — wire:click,
    wire:navigate, the Ctrl+K box's data attributes and its active styling —
    is passed straight through to the element.
--}}
@props([
    'label',
    'detail' => null,
    'image' => null,
    'icon' => null,
    'href' => null,
])

@php
    $tag = $href !== null ? 'a' : 'button';
@endphp

<{{ $tag }}
    @if ($href !== null) href="{{ $href }}" @else type="button" @endif
    {{ $attributes->class('flex w-full cursor-pointer items-center gap-3 rounded-lg px-2 py-1.75 text-left text-sm text-fg-soft transition-colors hover:bg-hover') }}
>
    <span class="grid size-8 shrink-0 place-items-center overflow-hidden rounded-md border border-line-strong bg-sunken">
        @if (filled($image))
            <img src="{{ $image }}" alt="" loading="lazy" class="size-full object-contain" />
        @elseif (filled($icon))
            <flux:icon :icon="$icon" variant="micro" class="size-4 text-fg-muted" />
        @endif
    </span>

    <span class="min-w-0 flex-1 truncate">{{ $label }}</span>

    @if (filled($detail))
        <span class="shrink-0 truncate text-xs text-fg-faint">{{ $detail }}</span>
    @endif
</{{ $tag }}>
