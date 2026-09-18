<?php

use App\Enums\MediaKind;
use App\Jobs\MatchGame;
use App\Jobs\ScrapeGameMedia;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\Media;
use App\Support\CoverGeometry;
use App\Support\MediaRegions;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Game')] #[Layout('layouts::app', ['bleed' => true])] class extends Component
{
    public Game $game;

    public function mount(Game $game): void
    {
        $this->game = $game;

        $this->loadRelations();
    }

    /**
     * Files in disc order, so a multi-disc set does not shuffle on a reload.
     *
     * Every reload goes through here rather than a bare load(), which would
     * drop the ordering mount() set up.
     */
    private function loadRelations(): void
    {
        $this->game->load([
            'files' => fn ($query) => $query->orderByRaw('disc_number IS NULL, disc_number')->orderBy('id'),
            // Ordered because the strip, the viewer's set and its "3 / 12"
            // counter are one list, and they have to agree on it.
            'media' => fn ($query) => $query->orderBy('id'),
        ]);
    }

    /**
     * What the game looked like when a lookup was queued, or null when idle.
     *
     * The poll is tied to this so the page stops asking as soon as an answer
     * lands. Deliberately not updated_at on its own: that column holds seconds,
     * so two changes inside one second are indistinguishable.
     */
    public ?string $awaiting = null;

    /** When the wait began, so a lookup that answers nothing still ends it. */
    public ?int $awaitingSince = null;

    /**
     * How long to keep asking.
     *
     * A retry on a game the provider still cannot name changes nothing at all,
     * so there is no answer to wait for — only a queue that has got to it.
     */
    private const WAIT_SECONDS = 120;

    public function identify(): void
    {
        if ($reason = $this->game->blockedFromLookup()) {
            Flux::toast(variant: 'warning', text: $reason);

            return;
        }

        $this->awaiting = $this->fingerprint();
        $this->awaitingSince = now()->timestamp;

        MatchGame::dispatch($this->game->id);

        Flux::toast(text: __('Identifying :title.', ['title' => $this->game->title]));
    }

    /** How many media the game had when a fetch was queued, or null when idle. */
    public ?int $fetchingFrom = null;

    public ?int $fetchingSince = null;

    public function fetchMedia(): void
    {
        if ($reason = $this->game->blockedFromMediaScrape()) {
            Flux::toast(variant: 'warning', text: $reason);

            return;
        }

        // Counted rather than fingerprinted: artwork arrives as new rows and
        // leaves the game itself untouched.
        $this->fetchingFrom = $this->game->media()->count();
        $this->fetchingSince = now()->timestamp;

        ScrapeGameMedia::dispatch($this->game->id);

        Flux::toast(text: __('Fetching artwork for :title.', ['title' => $this->game->title]));
    }

    /** Called by the poll while a fetch is outstanding. */
    public function checkMedia(): void
    {
        if ($this->game->media()->count() !== $this->fetchingFrom) {
            $this->fetchingFrom = null;
            $this->fetchingSince = null;
            $this->loadRelations();
            $this->forgetArtwork();

            return;
        }

        // A game whose artwork the provider does not hold adds nothing, so
        // there is no arrival to notice.
        if ($this->fetchingSince !== null && now()->timestamp - $this->fetchingSince >= self::WAIT_SECONDS) {
            $this->fetchingFrom = null;
            $this->fetchingSince = null;
        }
    }

    /** Called by the poll while a lookup is outstanding. */
    public function checkAnswer(): void
    {
        $this->game->refresh();

        if ($this->fingerprint() !== $this->awaiting) {
            $this->stopWaiting();
            $this->loadRelations();
            $this->forgetArtwork();
            unset($this->files, $this->primaryFile, $this->fileRows, $this->libraryPath);

            return;
        }

        if ($this->awaitingSince !== null && now()->timestamp - $this->awaitingSince >= self::WAIT_SECONDS) {
            $this->stopWaiting();
        }
    }

    private function stopWaiting(): void
    {
        $this->awaiting = null;
        $this->awaitingSince = null;
    }

    /** Drop the memoised artwork after the relation underneath it moved. */
    private function forgetArtwork(): void
    {
        unset($this->cover, $this->logo, $this->backdrop, $this->gallery);
    }

    /** Everything a lookup can change about the game itself. */
    private function fingerprint(): string
    {
        return implode('|', [
            $this->game->status->value,
            (string) $this->game->screenscraper_id,
            (string) $this->game->title,
            (string) $this->game->updated_at?->getTimestamp(),
        ]);
    }

    /** @return Collection<int, GameFile> */
    #[Computed]
    public function files(): Collection
    {
        return $this->game->files;
    }

    /**
     * The file the page speaks for.
     *
     * The one a lookup would use, so the header describes the game rather than
     * whichever cuesheet happened to sort first. Falls back to any file at
     * all, since a game whose every file has gone missing still has a page.
     */
    #[Computed]
    public function primaryFile(): ?GameFile
    {
        return $this->game->identifiableFile() ?? $this->files->first();
    }

    /**
     * One row per file, ready for the table.
     *
     * Built here rather than in the markup because every column is a small
     * formatting decision, and a game is a set of files — one disc image, or a
     * playlist over four cuesheets and four tracks.
     *
     * @return array<int, array{
     *     id: int,
     *     filename: string,
     *     folder: string,
     *     role: string,
     *     disc: string,
     *     size: string,
     *     format: string,
     *     added: string,
     *     lastSeen: string,
     *     missing: bool,
     *     md5: string|null,
     * }>
     */
    #[Computed]
    public function fileRows(): array
    {
        return $this->files
            ->map(fn (GameFile $file) => [
                'id' => $file->id,
                'filename' => $file->filename,
                'folder' => $this->subfolder($file),
                'role' => $file->role->label(),
                'disc' => $file->disc_number !== null ? (string) $file->disc_number : '—',
                'size' => $file->size_bytes !== null ? Number::fileSize($file->size_bytes, 1) : '—',
                'format' => Str::upper($file->extension),
                'added' => $this->relative($file->created_at),
                // The library holds no last-seen stamp, so the honest answer is
                // whether the file is there now, and how long ago it went if not.
                'lastSeen' => $file->isPresent() ? __('Present') : $this->relative($file->missing_since),
                'missing' => ! $file->isPresent(),
                'md5' => $file->md5,
            ])
            ->values()
            ->all();
    }

    /**
     * Where the file sits under the console's folder, or '' when at its root.
     *
     * Shown per row because one game can straddle subfolders, which the card
     * header's single path cannot say.
     */
    private function subfolder(GameFile $file): string
    {
        $directory = Str::beforeLast($file->path, '/');

        if ($directory === $file->path) {
            return '';
        }

        $folder = (string) $this->game->console()?->folder;

        return trim(Str::after($directory, $folder), '/');
    }

    /** The artwork to lead with, if any has been fetched. */
    #[Computed]
    public function cover(): ?string
    {
        return $this->game->artwork(MediaKind::Cover)?->path;
    }

    /** The title treatment, shown beside the heading when the provider had one. */
    #[Computed]
    public function logo(): ?string
    {
        return $this->game->artwork(MediaKind::Logo)?->path;
    }

    /** The key art behind the hero. Its absence is the design's second state. */
    #[Computed]
    public function backdrop(): ?string
    {
        return $this->game->artwork(MediaKind::Backdrop)?->path;
    }

    /**
     * Every piece of artwork the page holds, for the strip and the viewer both.
     *
     * One list so the two agree on order and caption, keyed by path because
     * that is the one thing the hero cover knows about its own image — which is
     * how it opens the viewer in the right place without being told a position.
     *
     * @return array<int, array{key: string, src: string, caption: string}>
     */
    #[Computed]
    public function gallery(): array
    {
        return $this->game->media
            ->map(fn (Media $media) => [
                'key' => $media->path,
                'src' => route('media.show', ['path' => $media->path]),
                'caption' => $this->caption($media),
            ])
            ->values()
            ->all();
    }

    /**
     * What to print under a full-screen image, e.g. "Cover · Europe".
     *
     * The provider's raw type stands in when the media fills no slot we have a
     * name for, and a region-less image is captioned by its kind alone.
     */
    private function caption(Media $media): string
    {
        $kind = MediaKind::fromScreenScraperType($media->screenscraper_type);

        return Collection::make([
            $kind?->label() ?? $media->screenscraper_type,
            MediaRegions::label($media->region),
        ])->filter()->implode(' · ');
    }

    /** Path as the library holds it — the real filesystem path is never shown. */
    #[Computed]
    public function libraryPath(): string
    {
        $file = $this->primaryFile;

        if ($file === null) {
            return '—';
        }

        return basename((string) config('settings.games_path')).'/'.$file->path;
    }

    /** The amber eyebrow: the console, then the year when one is known. */
    #[Computed]
    public function kicker(): string
    {
        return Collection::make([
            $this->game->console()?->name,
            Str::substr((string) $this->game->release_date, 0, 4) ?: null,
        ])->filter()->implode(' · ');
    }

    /**
     * The badges beside the region flag.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function chips(): array
    {
        return Collection::make([$this->game->console()?->name, $this->game->release_date])
            ->filter()
            ->map(fn (mixed $chip) => (string) $chip)
            ->values()
            ->all();
    }

    /**
     * The four-up metadata grid. Empty values are dropped rather than dashed,
     * so the grid is narrower when the provider held less, not gappy.
     *
     * @return array<int, array{key: string, value: string}>
     */
    #[Computed]
    public function detailRows(): array
    {
        return Collection::make([
            ['key' => __('Developer'), 'value' => $this->game->developer],
            ['key' => __('Publisher'), 'value' => $this->game->publisher],
            ['key' => __('Genre'), 'value' => $this->game->genre],
            ['key' => __('Players'), 'value' => $this->game->players],
        ])
            ->filter(fn (array $row) => filled(Arr::get($row, 'value')))
            ->map(fn (array $row) => [
                'key' => (string) Arr::get($row, 'key'),
                'value' => (string) Arr::get($row, 'value'),
            ])
            ->values()
            ->all();
    }

    /**
     * How tall the console's covers stand, in pixels.
     *
     * A SNES box is wide and flat where a PS2 case is tall, so the shelf is
     * levelled by height and each cover keeps its own width.
     */
    #[Computed]
    public function coverHeight(): int
    {
        return CoverGeometry::height($this->game->console());
    }

    /**
     * How wide a placeholder stands at that height.
     *
     * Nothing is there to give the box a width of its own, so the console's
     * ratio supplies one and the empty slot occupies the space its cover would.
     */
    #[Computed]
    public function coverPlaceholderWidth(): int
    {
        return CoverGeometry::width($this->game->console());
    }

    /** The region flag, or null when no picture depicts this code. */
    #[Computed]
    public function regionIcon(): ?string
    {
        return MediaRegions::icon($this->game->region);
    }

    /** The region's name, for the flag's title or the chip standing in for it. */
    #[Computed]
    public function regionLabel(): ?string
    {
        return MediaRegions::label($this->game->region);
    }

    /**
     * A short age, e.g. "3d ago".
     *
     * Anything under a minute reads as "just now", since the scan that wrote
     * it has only just finished.
     */
    private function relative(?CarbonInterface $at): string
    {
        if ($at === null) {
            return '—';
        }

        return abs($at->diffInSeconds()) < 60 ? __('just now') : $at->diffForHumans(short: true);
    }
}; ?>

@php($console = $game->console())

{{-- The viewer wraps the page so the hero cover and the artwork strip open the
     same set, far apart in the markup as they are. Only images carrying
     data-lightbox open it — the hero logo is artwork too, and is not one. --}}
<x-lightbox :images="$this->gallery" selector="[data-lightbox]" class="pb-14">
    {{-- Hero: the backdrop runs to the edges and the detail block is pulled up
         over its lower half, so the poster and title sit on the art.
         These two heights are load-bearing: the detail block below pulls up by
         -mt-[190px] / lg:-mt-[450px], which is each of these minus the intended
         overlap. Change one and change the other. --}}
    <div class="relative h-[300px] lg:h-[560px]">
        @if ($this->backdrop)
            <div class="absolute inset-0 bg-cover bg-[position:50%_28%]"
                 style="background-image: url('{{ route('media.show', ['path' => $this->backdrop]) }}')"></div>
        @endif

        <div class="absolute inset-0 hero-fade-y"></div>

        @if ($this->backdrop)
            @scanlines
                <div class="scanlines absolute inset-0"></div>
            @endscanlines
        @endif

        {{-- pl-14 clears the floating hamburger, which sits at top-4 left-4. --}}
        <div class="absolute top-4 right-4 left-4 flex items-center gap-3.5 pl-14 lg:top-5.5 lg:inset-x-7.5 lg:pl-0">
            <a
                href="{{ $console !== null ? route('consoles.games', ['console' => $console->key]) : route('games.index') }}"
                wire:navigate
                class="flex items-center gap-1.5 rounded-lg border border-line-input bg-scrim/60 px-2.75 py-1.5 text-sm text-fg-soft backdrop-blur-sm transition-colors hover:border-line-bright focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
            >
                <flux:icon.arrow-left class="size-3.5" />
                {{ $console?->name ?? __('Library') }}
            </a>

            @php($identifyBlocked = $game->blockedFromLookup())
            @php($mediaBlocked = $game->blockedFromMediaScrape())

            {{-- Hand-written rather than flux:dropdown: the panel's ground,
                 border, radius, padding and shadow all differ from flux:menu's,
                 and its popover geometry ignores the offsets this design needs. --}}
            <div
                x-data="{ open: false }"
                x-on:click.outside="open = false"
                x-on:keydown.escape.window="open = false"
                wire:key="actions"
                class="relative ml-auto"
            >
                <button
                    type="button"
                    x-on:click="open = ! open"
                    x-bind:aria-expanded="open"
                    aria-haspopup="menu"
                    class="flex cursor-pointer items-center gap-1.75 rounded-lg border border-line-input bg-scrim/60 px-3 py-1.75 text-sm text-fg-soft backdrop-blur-sm transition-colors hover:border-line-bright focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
                >
                    <flux:icon.ellipsis-horizontal class="size-3.5" />
                    {{ __('Actions') }}
                    <flux:icon.chevron-down class="size-[11px] text-fg-dim" />
                </button>

                <div
                    x-show="open"
                    x-cloak
                    role="menu"
                    class="absolute top-10 right-0 z-20 w-[214px] rounded-xl border border-line-input bg-surface p-1.25 shadow-2xl"
                >
                    <button
                        type="button"
                        role="menuitem"
                        @disabled($identifyBlocked !== null)
                        @if ($identifyBlocked !== null) title="{{ $identifyBlocked }}" @else wire:click="identify" @endif
                        @class([
                            'flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm text-fg-soft transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep',
                            'cursor-pointer hover:bg-raised' => $identifyBlocked === null,
                            'cursor-not-allowed opacity-45' => $identifyBlocked !== null,
                        ])
                    >
                        <flux:icon.sparkles class="size-[15px] text-fg-muted" />
                        {{-- A game the provider could not name is exactly where a
                             rename or a fresh dump makes another try worth it. --}}
                        {{ $game->status === App\Enums\GameStatus::Unmatched ? __('Try identifying again') : __('Identify game') }}
                    </button>

                    <button
                        type="button"
                        role="menuitem"
                        @disabled($mediaBlocked !== null)
                        @if ($mediaBlocked !== null) title="{{ $mediaBlocked }}" @else wire:click="fetchMedia" @endif
                        @class([
                            'flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm text-fg-soft transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep',
                            'cursor-pointer hover:bg-raised' => $mediaBlocked === null,
                            'cursor-not-allowed opacity-45' => $mediaBlocked !== null,
                        ])
                    >
                        <flux:icon.photo class="size-[15px] text-fg-muted" />
                        {{ $game->media->isEmpty() ? __('Fetch artwork') : __('Fetch artwork again') }}
                    </button>

                    {{-- Present because the design has it. Moving a file needs a
                         containment gate the rewrite has not built yet, so it
                         carries no handler at all rather than a half of one. --}}
                    <button
                        type="button"
                        role="menuitem"
                        disabled
                        title="{{ __('Not available yet.') }}"
                        class="flex w-full cursor-not-allowed items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm text-fg-soft opacity-45 transition-colors"
                    >
                        <flux:icon.folder-open class="size-[15px] text-fg-muted" />
                        {{ __('Move to folder') }}
                    </button>

                    <div class="my-1.25 mx-2 h-px bg-line"></div>

                    <x-copy-button variant="menu" role="menuitem" :text="$this->libraryPath" :label="__('Copy path')">
                        <flux:icon.document-duplicate class="size-[15px] text-fg-muted" />
                    </x-copy-button>

                    <button
                        type="button"
                        role="menuitem"
                        disabled
                        title="{{ __('Not available yet.') }}"
                        class="flex w-full cursor-not-allowed items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm text-danger opacity-45 transition-colors"
                    >
                        <flux:icon.trash class="size-[15px]" />
                        {{ __('Delete file') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="relative -mt-[190px] grid items-start gap-4.5 px-4 lg:-mt-[450px] lg:grid-cols-[auto_minmax(0,1fr)] lg:gap-6.5 lg:px-8">
        {{-- Height comes from the console's own config and the width follows the
             art, so a shelf of SNES boxes lines up without any of them being
             stretched. A placeholder has no art to take a width from, so the
             console's ratio supplies one. --}}
        <div class="w-fit max-w-full">
            @if ($this->cover)
                {{-- alt stays the game's name, which is what a reader needs
                     here; the viewer captions it from the gallery instead. --}}
                <img
                    data-lightbox="{{ $this->cover }}"
                    src="{{ route('media.show', ['path' => $this->cover]) }}"
                    alt="{{ $game->title }}"
                    style="height: {{ $this->coverHeight }}px"
                    class="block w-auto max-w-full rounded-xl border border-line-input object-contain shadow-lift"
                />
            @else
                <div
                    style="height: {{ $this->coverHeight }}px; width: {{ $this->coverPlaceholderWidth }}px"
                    class="flex max-w-full items-center justify-center rounded-xl border border-line-input bg-sunken shadow-lift"
                >
                    @if ($console !== null)
                        <img src="{{ $console->fileIcon }}" alt="{{ $console->name }}" class="h-20 w-20 object-contain opacity-25" />
                    @else
                        <flux:icon.photo class="size-8 text-fg-faint" />
                    @endif
                </div>
            @endif
        </div>

        <div class="min-w-0">
            <p class="kicker text-accent">{{ $this->kicker }}</p>

            <div class="mt-2 flex flex-wrap items-end gap-x-3.5 gap-y-1">
                @if ($this->logo)
                    <img src="{{ route('media.show', ['path' => $this->logo]) }}" alt="{{ $game->title }}" class="h-auto w-28 shrink-0" />
                @endif
                <h1 class="text-2xl font-medium tracking-display text-fg-bright lg:text-[34px]">{{ $game->title }}</h1>
            </div>

            {{-- No filename here: the Files table below names every one of them,
                 and a multi-disc game has no single one to show. --}}
            <div class="mt-4 flex flex-wrap items-center gap-1.75">
                {{-- 26px is exactly the badges' height beside it: text-xs's 16px
                     line box, their 8px of padding and 2px of border. Stated
                     outright because no spacing step lands on it. The width
                     follows, since region flags are not all one shape. --}}
                @if ($this->regionIcon)
                    <img
                        src="{{ $this->regionIcon }}"
                        alt="{{ $this->regionLabel ?? $game->region }}"
                        title="{{ $this->regionLabel ?? $game->region }}"
                        class="h-6.5 w-auto shrink-0 border-2 border-line-input"
                    />
                @elseif ($this->regionLabel)
                    <span class="rounded-md border border-line-strong bg-surface px-2 py-1 font-mono text-xs text-fg-muted">
                        {{ $this->regionLabel }}
                    </span>
                @endif

                @foreach ($this->chips as $chip)
                    <span class="rounded-md border border-line-strong bg-surface px-2 py-1 font-mono text-xs text-fg-muted">{{ $chip }}</span>
                @endforeach
            </div>

            @if ($this->detailRows !== [])
                <dl class="mt-5 grid w-fit max-w-full grid-cols-[repeat(2,max-content)] gap-x-8.5 gap-y-2 lg:grid-cols-[repeat(4,max-content)]">
                    @foreach ($this->detailRows as ['key' => $key, 'value' => $value])
                        <div>
                            <dt class="kicker text-fg-dim">{{ $key }}</dt>
                            <dd class="mt-1.25 text-sm text-fg-bright">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif

            <p class="mt-5 max-w-[100ch] text-sm leading-relaxed text-fg-muted text-pretty">
                {{ $game->description ?? __('No metadata yet — use Identify to fetch it.') }}
            </p>
        </div>
    </div>

    @if ($awaiting !== null || $fetchingFrom !== null)
        <section class="relative z-1 flex flex-col gap-3 px-4 pt-6.5 lg:px-8 lg:pt-10">
            @if ($awaiting !== null)
                <div wire:poll.3s="checkAnswer"
                     class="flex items-center gap-3 rounded-xl border border-accent-tint/55 bg-accent-tint/10 px-5 py-3">
                    <flux:icon.arrow-path class="size-4 animate-spin text-accent" />
                    <p class="text-sm text-accent">{{ __('Waiting for ScreenScraper…') }}</p>
                </div>
            @endif

            @if ($fetchingFrom !== null)
                <div wire:poll.3s="checkMedia"
                     class="flex items-center gap-3 rounded-xl border border-accent-tint/55 bg-accent-tint/10 px-5 py-3">
                    <flux:icon.arrow-path class="size-4 animate-spin text-accent" />
                    <p class="text-sm text-accent">{{ __('Fetching artwork…') }}</p>
                </div>
            @endif
        </section>
    @endif

    <section class="relative z-1 px-4 pt-6.5 lg:px-8 lg:pt-10">
        <div class="overflow-hidden rounded-xl border border-line bg-sunken">
            <div class="flex flex-wrap items-center gap-x-3.5 gap-y-2 border-b border-raised px-4.5 py-3.75">
                <flux:icon.archive-box class="size-[17px] text-fg-muted" />
                <h2 class="text-lg font-medium text-fg-bright">{{ __('Files') }}</h2>
                <span class="font-mono text-xs text-fg-dim">{{ count($this->fileRows) }}</span>
                {{-- The console's folder, not a file's: each row carries its own
                     subfolder, since one game can straddle several. --}}
                <span class="ml-auto font-mono text-xs break-all text-fg-dim">{{ $console?->libraryPath() ?? '—' }}</span>
            </div>

            {{-- Scrolls rather than wraps: eight columns of mono do not fold into
                 a phone, and a hash that has rewrapped is unreadable anyway. --}}
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-raised text-left">
                            <th class="kicker px-4.5 py-2.5 font-normal text-fg-faint">{{ __('File') }}</th>
                            <th class="kicker px-4.5 py-2.5 font-normal text-fg-faint">{{ __('Role') }}</th>
                            <th class="kicker px-4.5 py-2.5 font-normal text-fg-faint">{{ __('Disc') }}</th>
                            <th class="kicker px-4.5 py-2.5 font-normal text-fg-faint">{{ __('Size') }}</th>
                            <th class="kicker px-4.5 py-2.5 font-normal text-fg-faint">{{ __('Format') }}</th>
                            <th class="kicker px-4.5 py-2.5 font-normal text-fg-faint">{{ __('Added') }}</th>
                            <th class="kicker px-4.5 py-2.5 font-normal text-fg-faint">{{ __('Last seen') }}</th>
                            <th class="kicker px-4.5 py-2.5 font-normal text-fg-faint">{{ __('MD5') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->fileRows as [
                            'id' => $id,
                            'filename' => $filename,
                            'folder' => $folder,
                            'role' => $role,
                            'disc' => $disc,
                            'size' => $size,
                            'format' => $format,
                            'added' => $added,
                            'lastSeen' => $lastSeen,
                            'missing' => $missing,
                            'md5' => $md5,
                        ])
                            <tr wire:key="file-{{ $id }}" class="border-t border-raised first:border-t-0">
                                <td class="px-4.5 py-3">
                                    <div class="flex items-center gap-2">
                                        <span class="font-mono text-sm text-fg-bright">{{ $filename }}</span>

                                        @if ($missing)
                                            {{-- Kept rather than deleted: usually an unmounted disk, and
                                                 throwing the row away would mean identifying it again. --}}
                                            <span class="shrink-0 rounded-md border border-warn/50 px-2 py-0.5 font-mono text-xs text-warn">{{ __('Missing') }}</span>
                                        @endif
                                    </div>

                                    @if ($folder !== '')
                                        <p class="mt-0.5 font-mono text-xs text-fg-faint">{{ $folder }}</p>
                                    @endif
                                </td>
                                <td class="px-4.5 py-3 whitespace-nowrap text-fg-soft">{{ $role }}</td>
                                <td class="px-4.5 py-3 font-mono text-fg-muted">{{ $disc }}</td>
                                <td class="px-4.5 py-3 font-mono whitespace-nowrap text-fg-bright">{{ $size }}</td>
                                <td class="px-4.5 py-3 font-mono text-fg-muted">{{ $format }}</td>
                                <td class="px-4.5 py-3 font-mono whitespace-nowrap text-fg-muted">{{ $added }}</td>
                                <td @class([
                                    'px-4.5 py-3 font-mono whitespace-nowrap',
                                    'text-warn' => $missing,
                                    'text-fg-muted' => ! $missing,
                                ])>{{ $lastSeen }}</td>
                                <td class="px-4.5 py-3">
                                    @if ($md5)
                                        <div class="flex items-center gap-2">
                                            <span class="font-mono text-xs text-fg-soft">{{ $md5 }}</span>
                                            {{-- Icon only: a "Copy" beside every hash is noise in a
                                                 column that already repeats. It still says "Copied". --}}
                                            <x-copy-button :text="$md5" label="">
                                                <flux:icon.document-duplicate class="size-3.5 text-fg-faint" />
                                            </x-copy-button>
                                        </div>
                                    @else
                                        <span class="font-mono text-xs text-fg-faint">{{ __('Not hashed yet') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    @if ($this->gallery !== [])
        <section class="relative z-1 px-4 pt-6.5 lg:px-8 lg:pt-10">
            <div class="overflow-hidden rounded-xl border border-line bg-sunken">
                <div class="flex flex-wrap items-center gap-x-3.5 gap-y-2 border-b border-raised px-4.5 py-3.75">
                    <flux:icon.photo class="size-[17px] text-fg-muted" />
                    <h2 class="text-lg font-medium text-fg-bright">{{ __('Artwork') }}</h2>
                    <span class="ml-auto font-mono text-xs text-fg-dim">{{ count($this->gallery) }}</span>
                </div>

                <div class="flex flex-wrap gap-3 px-4.5 py-4">
                    @foreach ($this->gallery as ['key' => $key, 'src' => $src, 'caption' => $caption])
                        <img
                            wire:key="media-{{ $key }}"
                            data-lightbox="{{ $key }}"
                            src="{{ $src }}"
                            alt="{{ $caption }}"
                            title="{{ $caption }}"
                            class="h-20 w-auto rounded-lg border border-line-input bg-ground object-contain"
                        />
                    @endforeach
                </div>
            </div>
        </section>
    @endif
</x-lightbox>
