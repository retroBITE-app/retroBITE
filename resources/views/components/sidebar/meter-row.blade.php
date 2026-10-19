@props([
    'label',
    'percent' => null,
    // An idle row keeps its line but gives up the brighter figure, so the busy ones are where the eye lands.
    'quiet' => false,
])

{{-- One line inside a sidebar meter's fold: a label, its figure (the slot),
     and a bar under them. `bar` replaces the plain one, for a reading that
     wants x-quota-bar's warning colours. --}}
<div {{ $attributes }}>
    <div class="flex items-baseline justify-between gap-2 text-xs">
        <span class="truncate text-fg-faint">{{ $label }}</span>

        <span @class(['font-mono whitespace-nowrap', 'text-fg-dim' => ! $quiet, 'text-fg-faint' => $quiet])>{{ $slot }}</span>
    </div>

    @if (isset($bar))
        {{ $bar }}
    @elseif ($percent !== null)
        <x-progress-bar :percent="$percent" :label="$label" class="mt-1" />
    @endif
</div>
