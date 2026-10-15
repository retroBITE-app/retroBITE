@props([
    'title',
    'aside' => null,
])

{{-- A heading over one section of a page, with a figure or a link beside it
     on the right that wraps under the title on a narrow screen. --}}
<div {{ $attributes->class('mb-3.5 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2') }}>
    <h2 class="text-lg font-medium text-fg-bright">{{ $title }}</h2>

    @if ($aside instanceof Illuminate\View\ComponentSlot ? $aside->hasActualContent() : filled($aside))
        {{ $aside }}
    @endif
</div>
