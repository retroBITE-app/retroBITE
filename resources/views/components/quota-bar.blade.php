@props(['used', 'max', 'label' => null])

@php
    // Zero max means the response carried no limit for this counter. Not the
    // same as a spent allowance, so it gets an empty track and no percentage.
    $percent = $max > 0 ? min((int) round($used / $max * 100), 100) : null;

    // Warn from three quarters spent, danger from nine tenths.
    $tone = match (true) {
        $percent !== null && $percent >= 90 => 'danger',
        $percent !== null && $percent >= 75 => 'warn',
        default => 'accent',
    };
@endphp

<x-progress-bar :percent="$percent" :tone="$tone" :label="$label" :attributes="$attributes" />
