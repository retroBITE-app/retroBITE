@props([
    'href',
    'current' => false,
    'icon' => null,
    // A picture in the icon's place, such as a console's own mark.
    'image' => null,
    'count' => null,
])

{{-- A row nested under a sidebar item, the consoles under Consoles and the
     pages under Tools, drawn alike so the two lists read as one kind of
     thing. Pass wire:navigate as on flux:sidebar.item. --}}
<a
    href="{{ $href }}"
    @if ($current) aria-current="page" @endif
    {{ $attributes->class([
        'flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm transition-colors focus-ring',
        'bg-accent-tint/13 text-accent shadow-rail' => $current,
        'text-fg-cool hover:bg-hover hover:text-fg' => ! $current,
    ]) }}
>
    @if ($image !== null)
        <img src="{{ $image }}" alt="" class="size-4 shrink-0 object-contain" />
    @elseif ($icon !== null)
        <flux:icon :icon="$icon" class="size-4 shrink-0" />
    @endif

    <span class="truncate">{{ $slot }}</span>

    @if ($count !== null)
        <span class="ms-auto font-mono text-xs text-fg-faint">{{ $count }}</span>
    @endif
</a>
