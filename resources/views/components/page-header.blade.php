@props([
    'kicker' => null,
    'title',
    'description' => null,
    'meta' => null,
    'actions' => null,
])

{{-- The top of a page: a mono kicker over the display-size title, a mono
     meta line or a sentence of description under it, and the page's own
     actions level with the title's foot on the right. --}}
<div {{ $attributes->class('mb-6 flex flex-wrap items-end justify-between gap-4') }}>
    <div class="min-w-0">
        @if (filled($kicker))
            <p class="kicker mb-1.5 text-fg-faint">{{ $kicker }}</p>
        @endif

        <h1 class="text-display font-medium tracking-display text-fg-bright">{{ $title }}</h1>

        @if ($meta instanceof Illuminate\View\ComponentSlot ? $meta->hasActualContent() : filled($meta))
            <p class="mt-1.5 font-mono text-xs text-fg-faint">{{ $meta }}</p>
        @endif

        @if (filled($description))
            <p class="mt-1.5 text-sm text-fg-muted">{{ $description }}</p>
        @endif
    </div>

    @if ($actions instanceof Illuminate\View\ComponentSlot ? $actions->hasActualContent() : filled($actions))
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endif
</div>
