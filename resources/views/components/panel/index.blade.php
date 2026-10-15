@props([
    'tone' => 'surface',
    'padded' => false,
    'divided' => false,
    'as' => 'div',
    'title' => null,
    'header' => null,
    'actions' => null,
])

{{-- A bordered, rounded box that holds a list, a table or a block of
     content. With a title, a header slot or actions it gains the header
     strip, and the padding and rules move to the body under it. --}}
@php
    $headed = filled($title)
        || ($header instanceof Illuminate\View\ComponentSlot ? $header->hasActualContent() : filled($header))
        || ($actions instanceof Illuminate\View\ComponentSlot ? $actions->hasActualContent() : filled($actions));

    $body = Arr::toCssClasses([
        'p-5' => $padded,
        'divide-y divide-raised' => $divided,
    ]);

    // A <ul> cannot hold the strip, so with one the list moves to the body.
    $outer = $headed && $as === 'ul' ? 'div' : $as;
@endphp

<{{ $outer }} {{ $attributes->class([
    'overflow-hidden rounded-xl border border-line',
    'bg-surface' => $tone !== 'sunken',
    'bg-sunken' => $tone === 'sunken',
    $body => ! $headed,
]) }}>
    @if ($headed)
        <x-panel.header
            :title="$title"
            :actions="$actions"
            :attributes="$header instanceof Illuminate\View\ComponentSlot ? $header->attributes : new Illuminate\View\ComponentAttributeBag"
        >{{ $header }}</x-panel.header>

        <{{ $as === 'ul' ? 'ul' : 'div' }} @if ($body !== '') class="{{ $body }}" @endif>{{ $slot }}</{{ $as === 'ul' ? 'ul' : 'div' }}>
    @else
        {{ $slot }}
    @endif
</{{ $outer }}>
