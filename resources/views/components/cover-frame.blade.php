@props([
    'src' => null,
    'console' => null,
    'size' => 'md',
    'label' => null,
    'alt' => '',
    'lightbox' => null,
    'overlay' => null,
    'icon' => null,
    'as' => 'div',
])

{{-- A game's cover in a frame of fixed shape, or, while it has none, the
     console's own icon faded on the frame's ground. Without a console it
     shows the icon given, such as a search row's page or doc, or else a
     photo glyph. sm and md are list thumbnails, poster a small portrait one;
     lg fills a shelf column at the console's cover shape, hero stands at the
     game page's height. The overlay slot sits over the art: a pick box, the
     console's logo, actions. As span keeps the frame valid inside a button. --}}
@php
    $hero = $size === 'hero';
    $shelf = $size === 'lg';

    // Inline rather than arbitrary classes: the numbers come out of config
    // at runtime, and Tailwind only generates what it sees in the source.
    $style = match (true) {
        $shelf => 'aspect-ratio: '.App\Support\CoverGeometry::aspect($console),
        $hero && filled($src) => 'height: '.App\Support\CoverGeometry::HERO_HEIGHT.'px',
        $hero => 'height: '.App\Support\CoverGeometry::HERO_HEIGHT.'px; width: '.App\Support\CoverGeometry::heroWidth($console).'px',
        default => null,
    };

    $iconSize = Arr::get([
        'sm' => 'size-5',
        'md' => 'size-7',
        'poster' => 'size-8',
        'lg' => 'size-14 max-w-1/2',
        'hero' => 'size-20',
    ], $size, 'size-7');

    $glyph = in_array($size, ['lg', 'hero'], true) ? 'size-8' : 'size-4';
@endphp

<{{ $as }}
    @if ($style !== null) style="{{ $style }}" @endif
    {{ $attributes->class([
        'relative flex shrink-0 items-center justify-center overflow-hidden border bg-sunken',
        'size-8 rounded-md border-line-strong' => $size === 'sm',
        'size-12 rounded-md border-line-strong' => $size === 'md',
        'h-18 w-13 rounded-md border-line-input' => $size === 'poster',
        'w-full rounded-xl border-line-strong' => $shelf,
        'w-fit max-w-full rounded-xl border-line-input shadow-lift' => $hero,
    ]) }}
>
    @if (filled($src))
        <img
            src="{{ $src }}"
            alt="{{ $alt }}"
            @if (filled($lightbox)) data-lightbox="{{ $lightbox }}" @endif
            @unless ($hero) loading="lazy" @endunless
            @if ($shelf)
                {{-- Filled, or cropped from the top when it stands taller than
                     the frame: a portrait Super Famicom box on a shelf of
                     landscape Super Nintendo ones keeps its band and title
                     rather than being squashed. Decided once the picture is
                     in, and at once for one the browser already had. A tenth
                     of slack, so a box a hair off the ratio is filled. --}}
                x-data="{ fit() { if ($el.naturalHeight > 0) { const tall = $el.naturalWidth / $el.naturalHeight < {{ round(App\Support\CoverGeometry::ratioOf($console) * 0.9, 4) }}; $el.classList.toggle('object-fill', ! tall); $el.classList.toggle('object-cover', tall); $el.classList.toggle('object-top', tall) } } }"
                x-init="$el.complete && fit()"
                x-on:load="fit()"
            @endif
            @class([
                'block',
                'size-full object-contain' => in_array($size, ['sm', 'md'], true),
                'size-full object-cover' => $size === 'poster',
                'size-full object-fill' => $shelf,
                'h-full w-auto max-w-full object-contain' => $hero,
            ])
        />
    @else
        <{{ $as }} @class([
            'flex size-full flex-col items-center justify-center gap-3',
            'bg-[linear-gradient(165deg,var(--color-raised),var(--color-sunken))]' => $shelf,
        ])>
            @if ($console !== null)
                <img src="{{ $console->fileIcon }}" alt="{{ $console->name }}" class="{{ $iconSize }} object-contain opacity-25" />
            @elseif (filled($icon))
                <flux:icon :icon="$icon" :variant="$glyph === 'size-4' ? 'micro' : 'outline'" class="{{ $glyph }} text-fg-muted" />
            @else
                <flux:icon.photo class="{{ $glyph }} text-fg-faint" />
            @endif

            @if (filled($label))
                <span class="kicker text-fg-faint">{{ $label }}</span>
            @endif
        </{{ $as }}>
    @endif

    {{ $overlay }}
</{{ $as }}>
