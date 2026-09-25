@props([
    // 'mark', 'logo-stacked' or 'logo-wide': a file in resources/svg.
    'variant' => 'mark',
    // The accessible name, or null where the logo is decoration beside a name.
    'label' => null,
])

@php
    // Inline rather than an <img>: the logo's reds are classes that the
    // stylesheet fills from the colour scheme, and CSS cannot reach inside an
    // image. The caller's attributes go onto the <svg> itself so width and
    // height classes size it.
    abort_unless(in_array($variant, ['mark', 'logo-stacked', 'logo-wide'], true), 500);

    $svg = Illuminate\Support\Facades\File::get(resource_path("svg/{$variant}.svg"));

    $a11y = filled($label)
        ? ['role' => 'img', 'aria-label' => $label]
        : ['aria-hidden' => 'true', 'focusable' => 'false'];
@endphp

{!! Illuminate\Support\Str::replaceFirst('<svg ', '<svg '.$attributes->merge($a11y)->toHtml().' ', $svg) !!}
