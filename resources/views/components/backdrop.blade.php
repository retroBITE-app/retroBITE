@props([
    'src' => null,
    'fade' => null,
    'position' => '50% 28%',
    'fallback' => false,
    'fixed' => false,
])

{{-- Key art laid behind a hero, a card or the whole page, filling the
     nearest positioned box (fixed: the viewport). hero-y and hero-x darken
     it into the ground for text over it; art-l and art-b mask it out, the
     scanlines with it, so it ends where the art does. Off centre by
     default: key art puts its subject above the middle more often than not.
     The slot is for any further layer over the art, under the scanlines. --}}
@php
    $mask = Arr::get(['art-l' => 'art-fade-l', 'art-b' => 'art-fade-b'], (string) $fade);
    $overlay = Arr::get(['hero-y' => 'hero-fade-y', 'hero-x' => 'hero-fade-x'], (string) $fade);
@endphp

<div aria-hidden="true" {{ $attributes->class([
    'pointer-events-none inset-0',
    'fixed' => $fixed,
    'absolute' => ! $fixed,
    (string) $mask => $mask !== null,
]) }}>
    @if (filled($src))
        <div class="absolute inset-0 bg-cover" style="background-image: url('{{ $src }}'); background-position: {{ $position }}"></div>
    @elseif ($fallback)
        {{-- No art yet: the band keeps its height and a ground of its own
             rather than collapsing to a bare heading. --}}
        <div class="absolute inset-0 bg-[linear-gradient(165deg,var(--color-raised),var(--color-sunken))]"></div>
    @endif

    @if ($overlay !== null)
        <div class="{{ $overlay }} absolute inset-0"></div>
    @endif

    {{ $slot }}

    @if (filled($src))
        @scanlines
            <div class="scanlines absolute inset-0"></div>
        @endscanlines
    @endif
</div>
