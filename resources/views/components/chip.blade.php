@props([
    'active' => false,
    'count' => null,
    'mono' => false,
    'dashed' => false,
    'icon' => null,
])

{{-- A toggle pill in a filter row: pressed narrows the list, pressing it
     again widens it back out. Dashed is the row's "add" or "show more" chip,
     an action rather than a filter, so it is never pressed. --}}
<button
    type="button"
    @unless ($dashed) aria-pressed="{{ $active ? 'true' : 'false' }}" @endunless
    {{ $attributes->class([
        'inline-flex cursor-pointer items-center gap-1 rounded-lg border px-2.5 py-1 text-xs transition-colors focus-ring',
        'font-mono' => $mono,
        'border-dashed border-line-bright text-fg-dim hover:bg-hover hover:text-fg' => $dashed,
        'border-accent-tint/55 bg-accent-tint/10 text-accent' => ! $dashed && $active,
        'border-line-input text-fg-dim hover:bg-hover hover:text-fg' => ! $dashed && ! $active,
    ]) }}
>
    @if ($icon)
        <flux:icon :icon="$icon" variant="micro" class="size-3.5 shrink-0" />
    @endif
    {{ $slot }}
    @if ($count !== null)
        <span class="ml-1 font-mono text-fg-faint">{{ $count }}</span>
    @endif
</button>
