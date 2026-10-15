@props([
    'title' => null,
    'actions' => null,
])

{{-- The strip across the top of a panel: a kicker title, whatever the
     caller adds beside it, and actions pushed to the far edge. Its own
     component so a panel can rule off a second section mid-way down. --}}
<div {{ $attributes->class('flex flex-wrap items-center gap-3 border-b border-raised px-4.5 py-3.5') }}>
    @if (filled($title))
        <p class="kicker text-fg-faint">{{ $title }}</p>
    @endif

    {{ $slot }}

    @if ($actions instanceof Illuminate\View\ComponentSlot ? $actions->hasActualContent() : filled($actions))
        <div class="ml-auto flex items-center gap-2">{{ $actions }}</div>
    @endif
</div>
