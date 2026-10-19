@props([
    'percent' => null,
    'tone' => 'accent',
    'size' => 'sm',
    'xPercent' => null,
    'label' => null,
])

{{-- A thin track and its fill. The width is set from PHP, or, where Alpine
     owns the number (x-percent, a JS expression), bound in the browser; with
     neither the track stands empty and claims no value. A bar that claims one
     is a progressbar, and the label names what it measures. --}}
@php
    $known = $percent !== null;
    $width = $known ? round(max(0, min(100, (float) $percent)), 2) : 0;
@endphp

<div
    @if ($known || $xPercent !== null) role="progressbar" aria-valuemin="0" aria-valuemax="100" @endif
    @if (($known || $xPercent !== null) && filled($label)) aria-label="{{ $label }}" @endif
    @if ($known) aria-valuenow="{{ (int) round($width) }}" @endif
    @if ($xPercent !== null) x-bind:aria-valuenow="Math.round({{ $xPercent }})" @endif
    {{ $attributes->class([
        'overflow-hidden rounded-sm bg-raised',
        'h-[3px]' => $size === 'xs',
        'h-1' => $size === 'sm',
        'h-1.5' => $size === 'md',
    ]) }}
>
    <div
        @class([
            'h-full rounded-sm transition-[width] duration-300',
            'bg-accent-deep' => $tone === 'accent',
            'bg-warn' => $tone === 'warn',
            'bg-danger' => $tone === 'danger',
        ])
        @if ($xPercent !== null)
            x-bind:style="'width:' + ({{ $xPercent }}) + '%'"
        @else
            style="width: {{ $width }}%"
        @endif
    ></div>
</div>
