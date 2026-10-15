@props([
    'tone' => 'neutral',
    'size' => 'xs',
    'dashed' => false,
    'fill' => false,
    'icon' => null,
    'tip' => null,
])

{{-- A tag: a status, a format, a flag. One shape for all of them, so a
     colour is the only thing that says which is which. xs is the uppercase
     micro tag; sm the taller, normal-case one a file card's footer rows. --}}
@php
    $classes = Arr::toCssClasses([
        'inline-flex shrink-0 items-center rounded-md border font-mono',
        'gap-1 px-1.5 py-0.5 text-[10px] tracking-kicker uppercase' => $size === 'xs',
        // A fixed height so every badge in a row matches whatever it holds, a
        // flag or a line of text. Each line is trimmed to cap height and
        // baseline, so the mono font's deep descent cannot push the text off
        // centre; text alone needs a block box for that.
        'h-5.5 content-center justify-center gap-1.5 px-2 text-center text-xs leading-none tracking-normal whitespace-nowrap normal-case' => $size === 'sm',
        'not-has-[>*]:block [text-box:trim-both_cap_alphabetic] *:[text-box:trim-both_cap_alphabetic]' => $size === 'sm',
        'border-line-strong text-fg-muted' => $tone === 'neutral',
        'border-accent-tint/55 text-accent' => $tone === 'accent',
        'border-success/50 text-success' => $tone === 'success',
        'border-warn/50 text-warn' => $tone === 'warn',
        'border-danger/50 text-danger' => $tone === 'danger',
        'border-line-strong text-fg-faint' => $tone === 'muted',
        'border-dashed' => $dashed,
        'bg-sunken' => $fill && in_array($tone, ['neutral', 'muted'], true),
        'bg-accent-tint/10' => $fill && $tone === 'accent',
        'bg-success/10' => $fill && $tone === 'success',
        'bg-warn/10' => $fill && $tone === 'warn',
        'bg-danger/10' => $fill && $tone === 'danger',
        'cursor-help' => $tip !== null,
    ]);
@endphp

@if ($tip !== null)
    <flux:tooltip position="top">
        <span {{ $attributes->class($classes) }}>
            @if ($icon)
                <flux:icon :icon="$icon" variant="micro" class="size-3" />
            @endif
            {{ $slot }}
        </span>

        {{-- Held to the info icon's width, so a long one wraps; in the page's
             own face rather than the badge's mono. --}}
        <flux:tooltip.content class="max-w-xs font-sans">{{ $tip }}</flux:tooltip.content>
    </flux:tooltip>
@else
    <span {{ $attributes->class($classes) }}>
        @if ($icon)
            <flux:icon :icon="$icon" variant="micro" class="size-3" />
        @endif
        {{ $slot }}
    </span>
@endif
