@props([
    // An Alpine expression for what it opens: adds a turning chevron and aria-expanded.
    'chevron' => null,
])

{{--
    An amber word to click inside a line of text: a button, or an <a> when
    given an href. With $chevron it is a Show / Hide toggle for something
    folded below.
--}}
@php
    $tag = $attributes->has('href') ? 'a' : 'button';
@endphp

<{{ $tag }}
    @if ($tag === 'button') type="button" @endif
    @if ($chevron !== null) x-bind:aria-expanded="{{ $chevron }}" @endif
    {{ $attributes->class([
        'cursor-pointer text-accent hover:underline focus-ring',
        'inline-flex items-center gap-0.5' => $chevron !== null,
    ]) }}
>
    {{ $slot }}

    @if ($chevron !== null)
        <x-chevron :open="$chevron" />
    @endif
</{{ $tag }}>
