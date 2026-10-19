@props([
    'icon',
    'label',
    // `danger` for a button that removes or forgets something.
    'tone' => 'default',
    'size' => 'md',
])

{{--
    A bare icon button: no border, a hover ground, and its label as both the
    screen reader's name and the hover title, since there is no text to read.
    An <a> when given an href.
--}}
@php
    $tag = $attributes->has('href') ? 'a' : 'button';
@endphp

<{{ $tag }}
    @if ($tag === 'button') type="button" @endif
    aria-label="{{ $label }}"
    title="{{ $label }}"
    {{ $attributes->class([
        'grid shrink-0 cursor-pointer place-items-center rounded-md text-fg-faint transition-colors hover:bg-hover focus-ring disabled:cursor-not-allowed disabled:opacity-50',
        'size-7' => $size !== 'sm',
        'size-5' => $size === 'sm',
        'hover:text-danger' => $tone === 'danger',
        'hover:text-fg-soft' => $tone !== 'danger',
    ]) }}
>
    <flux:icon :name="$icon" :variant="$size === 'sm' ? 'micro' : 'outline'" :class="$size === 'sm' ? 'size-3.5' : 'size-4'" />
</{{ $tag }}>
