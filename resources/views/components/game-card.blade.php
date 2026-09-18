{{--
    One game on the library shelf.

    Levelled by height, which comes from the console's own config: a SNES box
    is wide and flat where a PS2 case is tall, and a shelf mixing the two has
    to line up somewhere. Width is the cover's own, because one console holds
    both shapes — a US Super Nintendo box is landscape and the Super Famicom
    one portrait — so any single width would crop one of them. Only a
    placeholder takes a width from config, having no art to take one from.
--}}
@props([
    'game',
    // The console's logo over the cover. For the all-consoles list, where a
    // card cannot otherwise say what it belongs to; noise on a console's own page.
    'showConsole' => false,
])

@php
    // Null for a console key config no longer carries — the card still renders,
    // it simply has no icons and falls back to the default geometry.
    $console = $game->console();
    $cover = $game->artwork(App\Enums\MediaKind::Cover)?->path;
    $regionIcon = App\Support\MediaRegions::icon($game->region);
    $regionLabel = App\Support\MediaRegions::label($game->region);

    // Height always; a width only when there is no art to supply one.
    $coverStyle = 'height: '.App\Support\CoverGeometry::height($console).'px';

    if ($cover === null) {
        $coverStyle .= '; width: '.App\Support\CoverGeometry::width($console).'px';
    }
@endphp

{{-- The link is stretched over the card rather than wrapping it, so the
     actions can sit above it instead of nesting a button inside an anchor. --}}
<div class="group relative flex max-w-full flex-col gap-2">
    <a
        href="{{ route('games.show', $game) }}"
        wire:navigate
        aria-label="{{ $game->title }}"
        class="absolute inset-0 z-10 rounded-xl focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-accent-deep"
    ></a>

    <div
        style="{{ $coverStyle }}"
        class="relative flex w-fit max-w-full items-center justify-center overflow-hidden rounded-xl border border-line-strong bg-sunken transition-colors group-hover:border-accent-tint/50"
    >
        @if ($cover !== null)
            <img
                src="{{ route('media.show', ['path' => $cover]) }}"
                alt="{{ $game->title }}"
                loading="lazy"
                class="block h-full w-auto max-w-full object-contain"
            />
        @else
            <div class="flex h-full w-full flex-col items-center justify-center gap-3 bg-[linear-gradient(165deg,var(--color-raised),var(--color-sunken))]">
                @if ($console !== null)
                    <img src="{{ $console->fileIcon }}" alt="{{ $console->name }}" class="h-14 w-14 object-contain opacity-25" />
                @else
                    <flux:icon.photo class="size-8 text-fg-faint" />
                @endif
                <span class="kicker text-fg-faint">{{ __('Unidentified') }}</span>
            </div>
        @endif

        @if ($showConsole && $console !== null)
            {{-- Over the art rather than beside the title: the caption row is
                 already the region and the size, and a long name leaves it no room. --}}
            <img
                src="{{ $console->icon }}"
                alt="{{ $console->name }}"
                title="{{ $console->name }}"
                class="absolute top-2 left-2 h-5 w-auto object-contain drop-shadow-[0_1px_3px_rgb(0_0_0/0.8)]"
            />
        @endif

        @if (! $actions->isEmpty())
            {{-- Out of the way until wanted, but reachable by keyboard:
                 focus-within, not hover alone. --}}
            <div class="absolute top-2 right-2 z-20 opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100">
                {{ $actions }}
            </div>
        @endif
    </div>

    {{-- w-0 min-w-full so the caption takes the width the cover just set
         without adding to it: the card is shrink-to-fit, and a long title
         would otherwise widen it instead of truncating. --}}
    <div class="w-0 min-w-full px-0.5">
        <p title="{{ $game->title }}" class="truncate text-sm text-fg transition-colors group-hover:text-fg-bright">
            {{ $game->title }}
        </p>

        <div class="mt-1 flex items-center gap-2">
            @if ($regionIcon !== null)
                <img src="{{ $regionIcon }}" alt="{{ $regionLabel }}" title="{{ $regionLabel }}" class="w-6 shrink-0 border border-line-input" />
            @elseif ($regionLabel !== null)
                <span class="font-mono text-xs text-fg-faint">{{ $regionLabel }}</span>
            @endif

            <span class="font-mono text-xs text-fg-dim">
                {{ Illuminate\Support\Number::fileSize((int) ($game->size_bytes_sum ?? 0), 1) }}
            </span>
        </div>
    </div>
</div>
