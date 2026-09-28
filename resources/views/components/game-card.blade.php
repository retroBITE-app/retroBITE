{{--
    One game on the library shelf.

    Every card on a shelf is the one shape its console's config gives —
    `cover_aspect` in config/consoles/*.php — identified or
    not, so a row is one height and a game still waiting on its art takes
    exactly the room it will have. The art fills that frame, never making its
    row taller or sitting in a band of empty ground: a box standing taller
    than the frame — a portrait Super Famicom one on a shelf of landscape
    Super Nintendo boxes — is cropped from the top down, anything else fitted
    to it. So the config's aspect is the one most of the console's boxes have;
    measure them before changing it.

    Fluid: the card fills its shelf column (see games.index, which picks how
    many to a row from the same shape), so every console has the same space
    between its cards, and the frame keeps its shape at any width.
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
    $coverMedia = $game->artwork(App\Enums\MediaKind::Cover);
    $cover = $coverMedia?->path;
    $regionIcon = App\Support\MediaRegions::icon($game->region);
    $regionLabel = App\Support\MediaRegions::label($game->region);

    // Inline styles rather than Tailwind arbitrary values: the numbers come
    // out of config at runtime, and a class Tailwind never sees in the source
    // is a class it never generates.
    $coverAspect = App\Support\CoverGeometry::aspect($console);
    $coverRatio = App\Support\CoverGeometry::ratioOf($console);

    // Selected by the library list off the joined progress row, and absent
    // everywhere else — so ?? rather than a bare read, or a card rendered from
    // a plain Game would throw.
    $achievementsPossible = (int) ($game->ra_achievements_possible ?? 0);
    $hardcorePrimary = App\Models\AppSetting::enabled(App\Models\AppSetting::RA_HARDCORE_PRIMARY);
    $unlocked = (int) (($hardcorePrimary ? $game->ra_unlocked_hardcore : $game->ra_unlocked) ?? 0);
    $achievementPercent = $achievementsPossible > 0 ? (int) round($unlocked / $achievementsPossible * 100) : 0;
@endphp

{{-- The link is stretched over the card rather than wrapping it, so the
     actions can sit above it instead of nesting a button inside an anchor.

     Hovering lights the whole card — cover, title and the region row under
     it — rather than only the art. What you are pointing at is a game, and
     the title is as much a part of it as the box.

     The border is always there and merely changes colour, because one that
     appears on hover is one pixel of layout arriving with it, and a shelf
     that twitches as the pointer crosses it is worse than no outline at all.
     The padding is likewise unconditional, which is what leaves the colour
     somewhere to go. --}}
<div class="group relative flex w-full flex-col gap-2 rounded-2xl border-3 border-transparent p-2 transition-colors hover:border-accent-tint/55 focus-within:border-accent-tint/55">
    <a
        href="{{ route('games.show', $game->routeParameters()) }}"
        wire:navigate
        aria-label="{{ $game->title }}"
        class="absolute inset-0 z-10 rounded-2xl focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-accent-deep"
    ></a>

    <div
        style="aspect-ratio: {{ $coverAspect }}"
        class="relative flex w-full items-center justify-center overflow-hidden rounded-xl border border-line-strong bg-sunken"
    >
        @if ($cover !== null)
            <img
                {{-- The shelf-sized copy (see MakeThumbnails), or the original
                     until it has been made. --}}
                src="{{ $coverMedia->url(App\Enums\ThumbnailSize::Grid) }}"
                alt="{{ $game->title }}"
                loading="lazy"
                {{-- Filled, or cropped when it stands taller than the frame:
                     a portrait Super Famicom box on a shelf of landscape
                     Super Nintendo ones keeps its top — the console's band
                     and the title, where those boxes put them — and loses
                     the rest below, rather than being squashed. Decided once the picture is in,
                     because nothing records its size until then — and at
                     once for one the browser already had, which can finish
                     loading before the listener is there to hear it. A
                     tenth of slack, so a box a hair off the config's ratio
                     is filled rather than trimmed. Swapped, not added: with
                     both on, object-fill comes later in the stylesheet and
                     wins. --}}
                x-data="{ fit() { if ($el.naturalHeight > 0) { const tall = $el.naturalWidth / $el.naturalHeight < {{ round($coverRatio * 0.9, 4) }}; $el.classList.toggle('object-fill', ! tall); $el.classList.toggle('object-cover', tall); $el.classList.toggle('object-top', tall) } } }"
                x-init="$el.complete && fit()"
                x-on:load="fit()"
                class="block size-full object-fill"
            />
        @else
            <div class="flex h-full w-full flex-col items-center justify-center gap-3 bg-[linear-gradient(165deg,var(--color-raised),var(--color-sunken))]">
                @if ($console !== null)
                    <img src="{{ $console->fileIcon }}" alt="{{ $console->name }}" class="size-14 max-w-1/2 object-contain opacity-25" />
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

            @if ($game->rating !== null)
                {{-- The far end of the region row. Off the artwork entirely:
                     over a cover it had to fight whatever was behind it, and
                     here it sits on the page's own ground, so the fill alone
                     carries it and it needs no ring or shadow to stay legible.

                     ms-auto rather than a spacer, so it holds the right edge
                     whether or not the game has a flag to its left.

                     Inline styles because the colour is chosen at runtime, and
                     a class Tailwind never saw in the source is one it never
                     generated — the same reason the geometry above is inline. --}}
                <span
                    title="{{ __('Rated :rating out of 100 by ScreenScraper', ['rating' => $game->rating]) }}"
                    style="background-color: {{ App\Support\RatingBand::color($game->rating) }}; color: {{ App\Support\RatingBand::ink() }}"
                    class="ms-auto shrink-0 rounded-md px-1.5 py-0.5 font-mono text-xs font-semibold tabular-nums"
                >{{ $game->rating }}</span>
            @endif
        </div>

        @if ($achievementsPossible > 0)
            {{-- Counts and a bar, no points: the game page is where the score
                 belongs, and a caption row this narrow can carry one number. --}}
            <div class="mt-1.5 flex items-center gap-2">
                <div class="h-1 flex-1 overflow-hidden rounded-sm bg-raised">
                    <div class="h-full rounded-sm bg-accent-deep transition-[width] duration-300" style="width: {{ $achievementPercent }}%"></div>
                </div>
                <span
                    class="shrink-0 font-mono text-xs text-fg-dim"
                    title="{{ $hardcorePrimary ? __('Hardcore achievements') : __('Achievements') }}"
                >{{ $unlocked }} / {{ $achievementsPossible }}</span>
            </div>
        @endif
    </div>
</div>
