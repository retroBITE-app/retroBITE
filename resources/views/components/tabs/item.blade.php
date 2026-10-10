@props([
    'active' => false,
    // A page to go to; without one the tab is a button, and its wire:click says what it does.
    'href' => null,
    // A Flux icon's name, or an image's URL (a console's icon), shown before the label.
    'icon' => null,
    'image' => null,
    // A number beside the label, as the game page counts its achievements and files.
    'count' => null,
])

@php
    $classes = [
        'flex shrink-0 cursor-pointer items-center gap-2 pb-2.75 text-sm whitespace-nowrap transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep',
        'text-fg-bright shadow-underline' => $active,
        'text-fg-muted hover:text-fg-soft' => ! $active,
    ];
@endphp

{{-- One tab of <x-tabs>. aria-current marks the current one, for screen
     readers and for tabStrip to scroll it into view. --}}
@if ($href !== null)
    <a href="{{ $href }}" wire:navigate @if ($active) aria-current="page" @endif {{ $attributes->class($classes) }}>
@else
    <button type="button" @if ($active) aria-current="page" @endif {{ $attributes->class($classes) }}>
@endif
    @if ($image !== null)
        <img src="{{ $image }}" alt="" class="size-4 object-contain" />
    @elseif ($icon !== null)
        <flux:icon :icon="$icon" class="size-4" />
    @endif

    {{-- Label and count on one baseline: the count is mono and smaller, and
         centred apart the two sit at two heights. --}}
    <span class="flex items-baseline gap-2">
        {{ $slot }}
        @if ($count !== null)
            <span class="font-mono text-xs text-fg-dim">{{ $count }}</span>
        @endif
    </span>
@if ($href !== null)
    </a>
@else
    </button>
@endif
