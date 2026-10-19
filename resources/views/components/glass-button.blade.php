@props([
    'icon' => null,
    'label' => null,
    // A trailing chevron, for a button that opens a menu.
    'chevron' => false,
    // Below sm the label goes to a screen reader and the chevron away.
    'compact' => false,
    // The icon alone in a square; $label becomes its aria-label.
    'iconOnly' => false,
])

{{--
    The smoked-glass button that sits on key art: translucent, blurred, quiet
    until hovered. Only a button, so it drops into a flux:dropdown as its
    trigger as it is.
--}}
<button
    type="button"
    @if ($iconOnly && $label !== null)
        aria-label="{{ $label }}"
    @endif
    {{ $attributes->class([
        'cursor-pointer rounded-lg border border-line-input bg-scrim/60 text-fg-soft backdrop-blur-sm transition-colors hover:border-line-bright hover:text-fg-bright focus-ring',
        'grid size-8 shrink-0 place-items-center p-0' => $iconOnly,
        'flex items-center gap-1.75 px-3 py-1.75 text-sm' => ! $iconOnly,
    ]) }}
>
    @if ($iconOnly)
        <flux:icon :name="$icon ?? 'ellipsis-horizontal'" class="size-4" />
    @else
        @if ($icon !== null)
            <flux:icon :name="$icon" class="size-3.5" />
        @endif

        <span @class(['max-sm:sr-only' => $compact])>{{ $slot->isEmpty() ? $label : $slot }}</span>

        @if ($chevron)
            <flux:icon.chevron-down :class="$compact ? 'size-[11px] text-fg-dim max-sm:hidden' : 'size-[11px] text-fg-dim'" />
        @endif
    @endif
</button>
