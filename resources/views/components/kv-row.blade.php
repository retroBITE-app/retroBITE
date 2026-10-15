@props([
    'label',
    'mono' => false,
])

{{-- One line of a settings card's key/value list, ruled off from the next,
     for a <dl class="flex flex-col gap-2.5">. The value is mono and
     right-aligned so a column of counts reads down evenly. --}}
<div {{ $attributes->class('flex items-baseline gap-3 border-b border-raised pb-2.5 last:border-0 last:pb-0') }}>
    <dt @class(['text-xs text-fg-soft', 'font-mono' => $mono])>{{ $label }}</dt>
    <dd class="ml-auto font-mono text-xs text-fg-dim tabular-nums">{{ $slot }}</dd>
</div>
