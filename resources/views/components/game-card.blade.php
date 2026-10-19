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
    $regionIcon = App\Support\MediaRegions::icon($game->region);
    $regionLabel = App\Support\MediaRegions::label($game->region);

    // Selected by the library list off the joined progress row, and absent
    // everywhere else — so ?? rather than a bare read, or a card rendered from
    // a plain Game would throw.
    $achievementsPossible = (int) ($game->ra_achievements_possible ?? 0);
    $hardcorePrimary = App\Models\AppSetting::enabled(App\Models\AppSetting::RA_HARDCORE_PRIMARY);
    $unlocked = (int) (($hardcorePrimary ? $game->ra_unlocked_hardcore : $game->ra_unlocked) ?? 0);
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

    {{-- The shelf-sized copy (see MakeThumbnails), or the original until it
         has been made, in a frame of the console's cover shape. --}}
    <x-cover-frame
        :src="$coverMedia?->url(App\Enums\ThumbnailSize::Grid)"
        :console="$console"
        size="lg"
        :label="__('Unidentified')"
        :alt="$game->title"
    >
        <x-slot:overlay>
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
        </x-slot:overlay>
    </x-cover-frame>

    {{-- w-0 min-w-full so the caption takes the width the cover just set
         without adding to it: the card is shrink-to-fit, and a long title
         would otherwise widen it instead of truncating. --}}
    <div class="w-0 min-w-full px-0.5">
        <p title="{{ $game->title }}" class="truncate text-sm text-fg transition-colors group-hover:text-fg-bright">
            {{ $game->title }}
        </p>

        <div class="mt-1 flex items-center gap-2">
            <x-region-flag :icon="$regionIcon" :label="$regionLabel" :code="$regionLabel" />

            <span class="font-mono text-xs text-fg-dim">
                {{ Illuminate\Support\Number::fileSize((int) ($game->size_bytes_sum ?? 0), 1) }}
            </span>

            {{-- Selected by the library list only, so ?? as above. --}}
            @if ($game->has_encrypted_files ?? false)
                <x-decrypt.encrypted />
            @endif

            <x-file-content :content="$game->extra_content ?? null" />

            {{-- The far end of the region row, off the artwork entirely: on the
                 page's own ground the fill alone carries it. ms-auto rather
                 than a spacer, so it holds the right edge with or without a
                 flag to its left. --}}
            <x-rating-badge
                :rating="$game->rating"
                class="ms-auto"
                title="{{ __('retroBite score :rating out of 100', ['rating' => $game->rating]) }}"
            />
        </div>

        @if ($achievementsPossible > 0)
            {{-- Counts and a bar, no points: the game page is where the score
                 belongs, and a caption row this narrow can carry one number. --}}
            <x-achievement-progress :unlocked="$unlocked" :possible="$achievementsPossible" :hardcore="$hardcorePrimary" class="mt-1.5" />
        @endif
    </div>
</div>
