@props(['pad' => 'sm'])

{{-- One band of a full-bleed page, such as the game page under its hero:
     the page's gutters, and above the art layered behind it. --}}
<section {{ $attributes->class([
    'relative z-1 px-4 lg:px-8',
    'pt-4.5' => $pad !== 'lg',
    'pt-6.5' => $pad === 'lg',
]) }}>
    {{ $slot }}
</section>
