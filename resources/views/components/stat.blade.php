@props([
    'label',
    'size' => 'md',
    'tone' => 'default',
    'mono' => null,
    'percent' => null,
    'sub' => null,
    'truncate' => false,
    'title' => null,
])

{{-- One figure under its kicker label, as a dt/dd pair for the caller's
     <dl>. md is a mono count; lg the sans figure of a stat grid, which can
     carry a bar for how far along it is and a line of detail under it.
     Truncate holds the figure to one line; the title, its whole text, then
     shows on hover over the figure. --}}
@php
    $mono ??= $size !== 'lg';
@endphp

<div {{ $attributes }}>
    <dt class="kicker text-fg-faint">{{ $label }}</dt>

    <dd @if (filled($title)) title="{{ $title }}" @endif @class([
        'tabular-nums',
        'truncate' => $truncate,
        'mt-1 text-lg' => $size !== 'lg',
        'mt-1.5 text-[19px] font-medium tracking-display' => $size === 'lg',
        'font-mono' => $mono,
        'text-fg-bright' => $tone !== 'warn',
        'text-warn' => $tone === 'warn',
    ])>{{ $slot }}</dd>

    @if ($sub instanceof Illuminate\View\ComponentSlot ? $sub->hasActualContent() : filled($sub))
        <dd class="mt-1 text-sm text-fg-dim">{{ $sub }}</dd>
    @endif

    @if ($percent !== null)
        <dd class="mt-2.5"><x-progress-bar :percent="$percent" :label="$label" size="xs" /></dd>
    @endif
</div>
