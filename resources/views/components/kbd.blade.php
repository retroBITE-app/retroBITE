@props(['keys'])

{{-- A key, or a few pressed together or in turn, set as keycaps. One key
     takes the attributes itself; several share a wrapper that does. --}}
@php($list = Arr::wrap($keys))

@if (count($list) === 1)
    <kbd {{ $attributes->class('font-sans text-xs text-fg-dim') }}>{{ Arr::first($list) }}</kbd>
@else
    <span {{ $attributes->class('inline-flex items-center gap-0.5') }}>
        @foreach ($list as $key)
            <kbd class="font-sans text-xs text-fg-dim">{{ $key }}</kbd>
        @endforeach
    </span>
@endif
