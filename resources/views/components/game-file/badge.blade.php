@props(['tip', 'warn' => false, 'archived' => false])

{{-- One badge on a file card, named on hover: with no column heading
     above it, "Game" or "RVZ" alone does not say what it is. --}}
<flux:tooltip position="top">
    {{-- A fixed height so every badge in the row matches whatever it holds,
         a flag or a line of text. Each line is trimmed to cap height and
         baseline, so the mono font's deep descent cannot push the text off
         centre; text alone needs a block box for that, so the flex layout
         is kept for badges with elements in them, such as flag and label. --}}
    <span {{ $attributes->class([
        'inline-flex h-5.5 cursor-help items-center justify-center content-center gap-1.5 rounded-md border px-2 text-center leading-none whitespace-nowrap',
        'not-has-[>*]:block [text-box:trim-both_cap_alphabetic] *:[text-box:trim-both_cap_alphabetic]',
        'border-line-strong bg-sunken' => ! $warn,
        'border-warn/50 text-warn' => $warn && ! $archived,
        'border-warn/30 text-warn/70' => $warn && $archived,
    ]) }}>{{ $slot }}</span>

    {{-- Held to the info icon's width, so a long one wraps; in the page's
         own face rather than the badge row's mono. --}}
    <flux:tooltip.content class="max-w-xs font-sans">{{ $tip }}</flux:tooltip.content>
</flux:tooltip>
