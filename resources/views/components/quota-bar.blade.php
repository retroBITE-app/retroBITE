@props(['used', 'max'])

@php
    // Zero max means the response carried no limit for this counter. Not the
    // same as a spent allowance, so it gets an empty track and no percentage.
    $percent = $max > 0 ? min((int) round($used / $max * 100), 100) : 0;
@endphp

<div {{ $attributes->class('h-1 overflow-hidden rounded-sm bg-raised') }}>
    <div @class([
        'h-full rounded-sm transition-[width] duration-300',
        'bg-danger' => $percent >= 90,
        'bg-warn' => $percent >= 75 && $percent < 90,
        'bg-accent-deep' => $percent < 75,
    ]) style="width: {{ $percent }}%"></div>
</div>
