@props([
    'title' => null,
    'actions' => null,
    'align' => 'center',
])

{{-- The strip across the top of a panel: a kicker title, whatever the
     caller adds beside it, and actions pushed to the far edge. Its own
     component so a panel can rule off a second section mid-way down. Align
     end sits the actions on the baseline of a header that runs to two lines. --}}
<div {{ $attributes->class([
    'flex flex-wrap gap-3 border-b border-raised px-4.5 py-3.5',
    'items-center' => $align !== 'end',
    'items-end' => $align === 'end',
]) }}>
    @if (filled($title))
        <p class="kicker text-fg-faint">{{ $title }}</p>
    @endif

    {{ $slot }}

    @if ($actions instanceof Illuminate\View\ComponentSlot ? $actions->hasActualContent() : filled($actions))
        <div class="ml-auto flex items-center gap-2">{{ $actions }}</div>
    @endif
</div>
