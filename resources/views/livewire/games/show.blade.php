<?php

use App\Enums\AchievementKind;
use App\Enums\MediaKind;
use App\Jobs\MatchGame;
use App\Jobs\ScrapeGameMedia;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\AppSetting;
use App\Models\Media;
use App\Models\RaAchievement;
use App\Models\RaGame;
use App\Models\RaProgress;
use App\Models\RaUnlock;
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
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Game')] #[Layout('layouts::app', ['bleed' => true])] class extends Component
{
    public Game $game;

    /**
     * Which achievements the panel lists: all | unlocked | locked.
     *
     * One of two axes, ANDed with the other. In the URL so a filtered view
     * can be linked.
     */
    #[Url(as: 'achievements')]
    public string $achievementFilter = 'all';

    /**
     * Narrow that to one kind: '' | missable | progression.
     *
     * Its own axis rather than two more pills on the first one, because
     * Locked AND Missable — what can I still lose? — is the question this
     * panel is worth opening for, and one row of mutually exclusive buttons
     * cannot ask it.
     *
     * Single-select all the same: kind is one column, so an achievement is
     * never both, and two kinds ANDed together would always be empty. A
     * second click on the button that is on clears it.
     */
    #[Url(as: 'kind')]
    public string $achievementKind = '';

    /** The region the fetch button would go and ask for. Not persisted. */
    public string $fetchRegion = '';

    /**
     * Which content panel is open, or '' for the first one this game has.
     *
     * Empty rather than 'achievements': the panel is only on the page for a
     * game that has a set, so a named default would point at nothing for most
     * of the library. In the URL so a panel can be linked.
     */
    #[Url(as: 'tab')]
    public string $tab = '';

    public function mount(Game $game): void
    {
        $this->game = $game;
    }

    /**
     * Order the relations on every request, not just the first.
     *
     * booted() rather than mount(): Livewire re-resolves the model from the
     * database on each update, so an ordering set up once at mount is gone by
     * the time somebody clicks a tab, and the relation comes back in whatever
     * order the table hands it over. That is usually insertion order and is
     * not promised to be — a deleted row, which a replaced piece of artwork
     * now leaves behind, is enough to change it. The visible result was a
     * strip and a file table that reshuffled between a click and a reload.
     */
    public function booted(): void
    {
        $this->loadRelations();
    }

    /**
     * Files in disc order, so a multi-disc set does not shuffle on a reload.
     *
     * Every reload goes through here rather than a bare load(), which would
     * drop the ordering.
     */
    private function loadRelations(): void
    {
        $this->game->load([
            'files' => fn ($query) => $query->orderByRaw('disc_number IS NULL, disc_number')->orderBy('id'),
            // Ordered because the strip, the viewer's set and its "3 / 12"
            // counter are one list, and they have to agree on it. gallery()
            // puts it in slot order from here.
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

    /** What artwork the game held when a fetch was queued, or null when idle. */
    public ?string $fetchingFrom = null;

    public ?int $fetchingSince = null;

    /**
     * Queue a scrape, optionally for one region by name.
     *
     * A named region is additive: it fetches that region's copies and leaves
     * every other region's alone, so the page ends up with something to
     * choose between. Without one the preference chain decides, as it always
     * has.
     */
    public function fetchMedia(?string $region = null): void
    {
        if ($reason = $this->game->blockedFromMediaScrape()) {
            Flux::toast(variant: 'warning', text: $reason);

            return;
        }

        $region = $region !== null && $region !== '' ? $region : null;

        if ($region !== null && ! array_key_exists($region, MediaRegions::labels())) {
            Flux::toast(variant: 'warning', text: __('Unknown region.'));

            return;
        }

        $this->fetchingFrom = $this->mediaFingerprint();
        $this->fetchingSince = now()->timestamp;

        ScrapeGameMedia::dispatch($this->game->id, null, $region);

        Flux::toast(text: $region !== null
            ? __('Fetching :region artwork for :title.', [
                'region' => MediaRegions::label($region) ?? $region,
                'title' => $this->game->title,
            ])
            : __('Fetching artwork for :title.', ['title' => $this->game->title]));
    }

    /** The button under the Artwork tab, which carries its own region. */
    public function fetchRegionMedia(): void
    {
        if ($this->fetchRegion === '') {
            Flux::toast(variant: 'warning', text: __('Choose a region first.'));

            return;
        }

        $this->fetchMedia($this->fetchRegion);
    }

    /**
     * Pick the region this game shows, or '' to follow the library setting.
     *
     * Written straight to the game rather than held in the component: it is
     * a property of the game, and the shelf and the console pages read it
     * too. Everything memoised off the artwork goes with it, which is what
     * moves the hero cover the moment the button is pressed.
     */
    public function useRegion(?string $region): void
    {
        $region = $region !== null && $region !== '' ? $region : null;

        if ($region !== null && ! array_key_exists($region, MediaRegions::labels())) {
            return;
        }

        $this->game->forceFill(['media_region' => $region])->save();

        $this->forgetArtwork();

        Flux::toast(text: $region === null
            ? __('Following the library setting again.')
            : __('Showing :region artwork.', ['region' => MediaRegions::label($region) ?? $region]));
    }

    /**
     * What the game's artwork looks like, for the poll to watch.
     *
     * Not a count: a scrape after a region change replaces a cover rather
     * than adding one, and the count comes back the same while the picture
     * on screen is a row that no longer exists. The highest id moves whether
     * a row was added or swapped.
     */
    private function mediaFingerprint(): string
    {
        return $this->game->media()->count().':'.(int) $this->game->media()->max('id');
    }

    /** Called by the poll while a fetch is outstanding. */
    public function checkMedia(): void
    {
        if ($this->mediaFingerprint() !== $this->fetchingFrom) {
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

    /**
     * Drop the memoised artwork after the relation underneath it moved.
     *
     * The tabs go with it: artwork arriving is what puts the Artwork tab on
     * the page, and its count is the gallery's.
     */
    private function forgetArtwork(): void
    {
        unset(
            $this->cover,
            $this->logo,
            $this->backdrop,
            $this->gallery,
            $this->galleryByRegion,
            $this->showingRegion,
            $this->fetchableRegions,
            $this->contentTabs,
            $this->activeTab,
        );
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
        // Cover, logo, backdrop, then everything the three slots have no room
        // for. By id the strip led with whichever row happened to be oldest,
        // and a replaced cover went to the back of a queue it used to head.
        $slots = array_flip(array_column(MediaKind::cases(), 'value'));

        return $this->game->media
            ->sortBy([
                fn (Media $a, Media $b) => $this->slot($a, $slots) <=> $this->slot($b, $slots),
                fn (Media $a, Media $b) => $a->screenscraper_type <=> $b->screenscraper_type,
                fn (Media $a, Media $b) => $a->id <=> $b->id,
            ])
            ->map(fn (Media $media) => [
                'key' => $media->path,
                'src' => route('media.show', ['path' => $media->path]),
                'caption' => $this->caption($media),
            ])
            ->values()
            ->all();
    }

    /**
     * The artwork grouped by the region it came from.
     *
     * The groups are in the order config/regions.php lists them, which is a
     * hand-written order, and they stay there. Not the preference chain:
     * that would lift whichever region is chosen to the top, so picking one
     * would rearrange the page under the hand that picked it — and the group
     * somebody wanted to compare against would move as well.
     *
     * A region with no label of its own comes after the named ones, and
     * region-less artwork — fanart and video carry none — is last and named
     * as such rather than pretending to be somewhere.
     *
     * @return array<int, array{region: ?string, label: string, icon: ?string, selected: bool, images: array<int, array{key: string, src: string, caption: string}>}>
     */
    #[Computed]
    public function galleryByRegion(): array
    {
        $byRegion = [];

        foreach ($this->game->media as $media) {
            $region = ($media->region ?? '') !== '' ? $media->region : null;
            $byRegion[$region ?? ''][] = $media->path;
        }

        $order = array_flip(array_keys(MediaRegions::labels()));
        $shown = $this->showingRegion;

        $keys = array_keys($byRegion);
        usort($keys, fn (string $a, string $b) => [
            $a === '' ? 2 : 0, $order[$a] ?? count($order), $a,
        ] <=> [
            $b === '' ? 2 : 0, $order[$b] ?? count($order), $b,
        ]);

        $images = collect($this->gallery)->keyBy('key');

        return array_map(fn (string $key) => [
            'region' => $key === '' ? null : $key,
            'label' => $key === ''
                ? __('No region')
                : (MediaRegions::label($key) ?? $key),
            'icon' => $key === '' ? null : MediaRegions::icon($key),
            'selected' => $key !== '' && $key === $shown,
            'images' => $images->only($byRegion[$key])->values()->all(),
        ], $keys);
    }

    /**
     * The region the page is actually showing.
     *
     * Not media_region: that is often null, meaning "whatever the settings
     * say", and a group headed "Selected" has to be the one the eye can see
     * at the top of the page. So it is asked of the artwork itself.
     */
    #[Computed]
    public function showingRegion(): ?string
    {
        $leading = $this->game->artwork(MediaKind::Cover)
            ?? $this->game->artwork(MediaKind::Logo)
            ?? $this->game->artwork(MediaKind::Backdrop);

        return ($leading?->region ?? '') !== '' ? $leading->region : null;
    }

    /**
     * What the fetch control offers.
     *
     * Every region we hold a name for, minus the ones already fetched: a
     * button that would re-download what is on screen is not a choice.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function fetchableRegions(): array
    {
        $held = $this->game->media->pluck('region')->filter()->unique()->all();

        return array_diff_key(MediaRegions::labels(), array_flip($held));
    }

    /**
     * Which of the three slots this artwork fills, or past the last for none.
     *
     * @param  array<string, int>  $slots
     */
    private function slot(Media $media, array $slots): int
    {
        $kind = MediaKind::fromScreenScraperType($media->screenscraper_type);

        return $kind === null ? count($slots) : $slots[$kind->value];
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
            // No rating here. It is on the chip row above, and a page cannot
            // say the same number twice without the reader wondering which
            // of the two is the other one.
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
     * The achievement set, with the achievements that count.
     *
     * Unofficial and demoted ones are stored but never shown: they do not
     * count towards a score on RetroAchievements either, so listing them would
     * make our totals disagree with theirs on the same page.
     */
    #[Computed]
    public function raGame(): ?RaGame
    {
        if ($this->game->retroachievements_id === null) {
            return null;
        }

        return RaGame::query()
            ->with(['achievements' => fn ($query) => $query->counting()
                ->orderBy('display_order')
                ->orderBy('id')])
            ->find($this->game->retroachievements_id);
    }

    /** This person's counters for the set, straight out of the table. */
    #[Computed]
    public function raProgress(): ?RaProgress
    {
        if ($this->game->retroachievements_id === null) {
            return null;
        }

        return RaProgress::query()
            ->where('user_id', auth()->id())
            ->where('ra_game_id', $this->game->retroachievements_id)
            ->first();
    }

    /**
     * Their unlocks for this set, keyed by achievement.
     *
     * One query for the whole grid rather than a lookup per card.
     *
     * @return Collection<int, RaUnlock>
     */
    #[Computed]
    public function unlocks(): Collection
    {
        if ($this->game->retroachievements_id === null) {
            return new Collection;
        }

        return RaUnlock::query()
            ->where('user_id', auth()->id())
            ->where('ra_game_id', $this->game->retroachievements_id)
            ->get()
            ->keyBy('ra_achievement_id');
    }

    /**
     * When they got this one, or null for an achievement still locked.
     *
     * The single answer to "is it unlocked", so the number on a button and
     * the cards it filters can never disagree. An unlock row on its own is
     * not enough: a reconcile can leave one behind with both dates cleared.
     */
    private function unlockedAt(RaAchievement $achievement): ?CarbonInterface
    {
        $unlock = $this->unlocks->get($achievement->id);

        return $unlock?->unlocked_at ?? $unlock?->unlocked_hardcore_at;
    }

    /** Where this achievement stands, for the first axis. */
    private function matchesState(RaAchievement $achievement, string $state): bool
    {
        return match ($state) {
            'unlocked' => $this->unlockedAt($achievement) !== null,
            'locked' => $this->unlockedAt($achievement) === null,
            default => true,
        };
    }

    /**
     * What this achievement is for, for the second axis.
     *
     * Progression takes the win condition with it: it is the last step of
     * beating the game, and a list of the steps that stops short of the one
     * that finishes it answers nobody's question.
     */
    private function matchesKind(RaAchievement $achievement, string $kind): bool
    {
        return match ($kind) {
            'missable' => $achievement->kind === AchievementKind::Missable,
            'progression' => in_array(
                $achievement->kind,
                [AchievementKind::Progression, AchievementKind::WinCondition],
                true,
            ),
            default => true,
        };
    }

    /**
     * The four figures across the top of the panel.
     *
     * Softcore and hardcore side by side rather than behind a switch: a game
     * can be 40/40 softcore and 3/40 hardcore at the same time, and either
     * number alone is a misleading description of where somebody is.
     *
     * @return array<int, array{label: string, value: string, percent: int}>
     */
    #[Computed]
    public function achievementStats(): array
    {
        $progress = $this->raProgress;
        $possible = (int) ($progress?->achievements_possible ?? 0);
        $pointsPossible = (int) ($progress?->points_possible ?? 0);

        return [
            [
                'label' => __('Unlocked'),
                'value' => ($progress?->unlocked_count ?? 0).' / '.$possible,
                'percent' => $possible > 0 ? (int) round(($progress?->unlocked_count ?? 0) / $possible * 100) : 0,
            ],
            [
                'label' => __('Points'),
                'value' => Number::format((int) ($progress?->points_earned ?? 0)).' / '.Number::format($pointsPossible),
                'percent' => $pointsPossible > 0 ? (int) round(($progress?->points_earned ?? 0) / $pointsPossible * 100) : 0,
            ],
            [
                'label' => __('Hardcore'),
                'value' => (string) ($progress?->unlocked_hardcore_count ?? 0),
                'percent' => $possible > 0 ? (int) round(($progress?->unlocked_hardcore_count ?? 0) / $possible * 100) : 0,
            ],
            [
                'label' => __('Site rank'),
                // Null is "they have no progress in this game", which is not
                // the same as rank zero and should not read like it.
                'value' => $progress?->site_rank !== null ? '#'.Number::format($progress->site_rank) : '—',
                'percent' => 0,
            ],
        ];
    }

    /**
     * The achievement cards, filtered by the tab.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function achievements(): array
    {
        $set = $this->raGame;

        if ($set === null) {
            return [];
        }

        $players = (int) $set->num_distinct_players;

        return $set->achievements
            ->filter(fn ($achievement) => $this->matchesState($achievement, $this->activeAchievementFilter)
                && $this->matchesKind($achievement, $this->activeAchievementKind))
            ->map(function ($achievement) use ($players) {
                $unlock = $this->unlocks->get($achievement->id);
                $unlockedAt = $this->unlockedAt($achievement);
                $rarity = $players > 0 ? round($achievement->num_awarded / $players * 100, 1) : null;

                return [
                    'id' => $achievement->id,
                    'title' => $achievement->title,
                    'description' => $achievement->description,
                    'points' => $achievement->points,
                    'kind' => $achievement->kind?->label(),
                    'unlocked' => $unlockedAt !== null,
                    'hardcore' => $unlock?->unlocked_hardcore_at !== null,
                    // The locked badge as well as the unlocked one, so a
                    // greyed-out card still shows what it is a picture of.
                    'badge' => $achievement->badgeUrl(locked: $unlockedAt === null),
                    'footnote' => $unlockedAt !== null
                        ? __('Unlocked :date', ['date' => $unlockedAt->format('d M Y')])
                        : ($rarity !== null
                            ? __('Locked · :percent% of players', ['percent' => $rarity])
                            : __('Locked')),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The first axis, each button carrying what it would leave on screen.
     *
     * Counted through the kind in force, so the numbers describe the panel
     * as it would actually be rather than the set in the abstract.
     *
     * @return array<int, array{key: string, label: string}>
     */
    #[Computed]
    public function achievementTabs(): array
    {
        $kind = $this->activeAchievementKind;

        $of = $this->countingAchievements->filter(fn ($achievement) => $this->matchesKind($achievement, $kind));

        $count = fn (string $state): int => $of
            ->filter(fn ($achievement) => $this->matchesState($achievement, $state))
            ->count();

        return [
            ['key' => 'all', 'label' => __('All :count', ['count' => $of->count()])],
            ['key' => 'unlocked', 'label' => __('Unlocked :count', ['count' => $count('unlocked')])],
            ['key' => 'locked', 'label' => __('Locked :count', ['count' => $count('locked')])],
        ];
    }

    /**
     * The second axis, offered only by a set that marks any.
     *
     * Presence is the set's whole list, so the row does not appear and
     * disappear as the first axis moves; the count is through the first
     * axis, so it still says what the button would leave. A zero there is
     * not a dead end — Unlocked · Missable 0 is the answer to a fair
     * question, and the button beside it says why.
     *
     * @return array<int, array{key: string, label: string, count: int}>
     */
    #[Computed]
    public function achievementKinds(): array
    {
        $all = $this->countingAchievements;
        $of = $all->filter(fn ($achievement) => $this->matchesState($achievement, $this->activeAchievementFilter));

        $kinds = [
            'missable' => __('Missable'),
            'progression' => __('Progression'),
        ];

        $offered = [];

        foreach ($kinds as $key => $label) {
            if ($all->contains(fn ($achievement) => $this->matchesKind($achievement, $key))) {
                $offered[] = [
                    'key' => $key,
                    'label' => $label,
                    'count' => $of->filter(fn ($achievement) => $this->matchesKind($achievement, $key))->count(),
                ];
            }
        }

        return $offered;
    }

    /**
     * The set's achievements, or an empty list for a game without one.
     *
     * @return Collection<int, RaAchievement>
     */
    #[Computed]
    public function countingAchievements(): Collection
    {
        return $this->raGame?->achievements ?? new Collection;
    }

    /** The first axis in force, guarding against a hand-written ?achievements=. */
    #[Computed]
    public function activeAchievementFilter(): string
    {
        return in_array($this->achievementFilter, ['all', 'unlocked', 'locked'], true)
            ? $this->achievementFilter
            : 'all';
    }

    /**
     * The kind in force.
     *
     * A ?kind= this set does not offer — a link to the missable ones of a set
     * that marks none — narrows nothing rather than emptying the panel.
     */
    #[Computed]
    public function activeAchievementKind(): string
    {
        $keys = array_column($this->achievementKinds, 'key');

        return in_array($this->achievementKind, $keys, true) ? $this->achievementKind : '';
    }

    public function filterAchievements(string $filter): void
    {
        $this->achievementFilter = $filter;

        $this->forgetAchievementFilters();
    }

    /** Click the kind that is already on to clear it. */
    public function toggleKind(string $kind): void
    {
        $this->achievementKind = $this->activeAchievementKind === $kind ? '' : $kind;

        $this->forgetAchievementFilters();
    }

    /** Each axis counts through the other, so moving one moves both rows. */
    private function forgetAchievementFilters(): void
    {
        unset(
            $this->achievements,
            $this->achievementTabs,
            $this->achievementKinds,
            $this->activeAchievementFilter,
            $this->activeAchievementKind,
        );
    }

    /**
     * The content panels this game has, in tab order.
     *
     * Two of the three come and go: a game with no achievement set has no
     * Achievements panel, and one nothing has been downloaded for has no
     * Artwork. Files is always there — a game is its files, and an empty
     * table still says where they were looked for.
     *
     * @return array<int, array{key: string, label: string, icon: string, count: int}>
     */
    #[Computed]
    public function contentTabs(): array
    {
        return array_values(array_filter([
            $this->raGame !== null ? [
                'key' => 'achievements',
                'label' => __('Achievements'),
                'icon' => 'trophy',
                'count' => $this->raGame->achievements->count(),
            ] : null,
            [
                'key' => 'files',
                'label' => __('Files'),
                'icon' => 'archive-box',
                'count' => count($this->fileRows),
            ],
            $this->gallery !== [] ? [
                'key' => 'artwork',
                'label' => __('Artwork'),
                'icon' => 'photo',
                'count' => count($this->gallery),
            ] : null,
        ]));
    }

    /**
     * The panel on screen.
     *
     * A ?tab= naming one this game does not have — a link to the achievements
     * of a game whose set has since gone — falls back to the first panel
     * rather than leaving the page with nothing under the tabs.
     */
    #[Computed]
    public function activeTab(): string
    {
        $keys = array_column($this->contentTabs, 'key');

        return in_array($this->tab, $keys, true) ? $this->tab : ($keys[0] ?? 'files');
    }

    public function selectTab(string $tab): void
    {
        $this->tab = $tab;

        unset($this->activeTab);
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

                @if ($game->rating !== null)
                    {{-- The same band colour and the same corners as the
                         shelf badge, so a game does not change verdict — or
                         shape — on the way here. Tinted rather than filled
                         though: this one stands in a row of chips, and a
                         solid block among them would read as a control. --}}
                    <span
                        title="{{ __('Rated :rating out of 100 by ScreenScraper', ['rating' => $game->rating]) }}"
                        @style([
                            'border-color: color-mix(in srgb, '.App\Support\RatingBand::color($game->rating).' 55%, transparent)',
                            'background-color: color-mix(in srgb, '.App\Support\RatingBand::color($game->rating).' 14%, transparent)',
                            'color: '.App\Support\RatingBand::color($game->rating),
                        ])
                        class="rounded-md border px-2 py-1 font-mono text-xs font-semibold tabular-nums"
                    >{{ $game->rating }} / 100</span>
                @endif
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

    {{-- One heading row for everything below it. Each panel drops the title
         it used to carry, since the tab now says it, and keeps only what is
         its own: the filter, the folder, the source. --}}
    <section class="relative z-1 px-4 pt-6.5 lg:px-8 lg:pt-10">
        <div class="flex gap-5.5 overflow-x-auto border-b border-raised">
            @foreach ($this->contentTabs as $contentTab)
                <button
                    type="button"
                    wire:key="tab-{{ $contentTab['key'] }}"
                    wire:click="selectTab('{{ $contentTab['key'] }}')"
                    @if ($this->activeTab === $contentTab['key']) aria-current="page" @endif
                    @class([
                        'flex shrink-0 cursor-pointer items-center gap-2 pb-2.75 text-sm whitespace-nowrap transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep',
                        'text-fg-bright shadow-underline' => $this->activeTab === $contentTab['key'],
                        'text-fg-muted hover:text-fg-soft' => $this->activeTab !== $contentTab['key'],
                    ])
                >
                    <flux:icon :name="$contentTab['icon']" class="size-4" />
                    {{ $contentTab['label'] }}
                    <span class="font-mono text-xs text-fg-dim">{{ $contentTab['count'] }}</span>
                </button>
            @endforeach
        </div>
    </section>

    @if ($this->activeTab === 'achievements')
        <section class="relative z-1 px-4 pt-4.5 lg:px-8">
            <div class="overflow-hidden rounded-xl border border-line bg-sunken">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-3 border-b border-raised px-4.5 py-3.75">
                    <span class="font-mono text-xs text-fg-dim">{{ __('RetroAchievements') }}</span>

                    @if (App\Models\AppSetting::enabled(App\Models\AppSetting::RA_HARDCORE_PRIMARY))
                        <span class="kicker rounded-md border border-accent/40 bg-accent-tint/10 px-1.75 py-0.75 text-accent">
                            {{ __('Hardcore') }}
                        </span>
                    @endif

                    {{-- Two axes, ANDed, in two groups so it reads as two
                         questions: where a thing stands, and what it is for.
                         One row of pills would read as one choice. --}}
                    <div class="ml-auto flex flex-wrap items-center gap-2">
                        <div class="flex flex-wrap gap-1 rounded-lg border border-line-input bg-ground p-0.75">
                            {{-- Not $tab: that is the component's own property,
                                 naming the panel this row sits in. --}}
                            @foreach ($this->achievementTabs as $filter)
                                <button
                                    type="button"
                                    wire:click="filterAchievements('{{ $filter['key'] }}')"
                                    @class([
                                        'cursor-pointer rounded-md px-2.5 py-1 text-xs transition-colors',
                                        'bg-raised text-fg-bright' => $this->activeAchievementFilter === $filter['key'],
                                        'text-fg-muted hover:text-fg' => $this->activeAchievementFilter !== $filter['key'],
                                    ])
                                >{{ $filter['label'] }}</button>
                            @endforeach
                        </div>

                        @if ($this->achievementKinds !== [])
                            <div class="flex flex-wrap gap-1 rounded-lg border border-line-input bg-ground p-0.75">
                                @foreach ($this->achievementKinds as $kind)
                                    {{-- A toggle, not a tab: pressed says the
                                         list is narrowed, and pressing it
                                         again widens it back out. --}}
                                    <button
                                        type="button"
                                        wire:click="toggleKind('{{ $kind['key'] }}')"
                                        aria-pressed="{{ $this->activeAchievementKind === $kind['key'] ? 'true' : 'false' }}"
                                        @class([
                                            'cursor-pointer rounded-md border px-2.5 py-1 text-xs transition-colors',
                                            'border-accent/40 bg-accent-tint/10 text-accent' => $this->activeAchievementKind === $kind['key'],
                                            'border-transparent text-fg-muted hover:text-fg' => $this->activeAchievementKind !== $kind['key'],
                                        ])
                                    >{{ $kind['label'] }} {{ $kind['count'] }}</button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>

                <dl class="grid grid-cols-2 border-b border-raised sm:grid-cols-4">
                    @foreach ($this->achievementStats as $stat)
                        <div @class([
                            'px-4.5 py-3.5',
                            'border-r border-raised' => ! $loop->last,
                        ])>
                            <dt class="kicker text-fg-faint">{{ $stat['label'] }}</dt>
                            <dd class="mt-1.5 text-[19px] font-medium tracking-display text-fg-bright">{{ $stat['value'] }}</dd>
                            <div class="mt-2.5 h-[3px] overflow-hidden rounded-sm bg-raised">
                                <div class="h-full rounded-sm bg-accent-deep transition-[width] duration-300" style="width: {{ $stat['percent'] }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </dl>

                @if ($this->achievements === [])
                    <p class="px-4.5 py-8 text-center text-sm text-fg-faint">
                        {{-- Two ways to be empty, and only one of them is
                             something to wait for. --}}
                        {{ $this->countingAchievements->isEmpty()
                            ? __('Nothing here yet. The set is fetched in the background.')
                            : __('No achievement matches both filters.') }}
                    </p>
                @else
                    <ul class="grid gap-2.5 p-4.5 [grid-template-columns:repeat(auto-fill,minmax(268px,1fr))]">
                        @foreach ($this->achievements as $achievement)
                            <li
                                wire:key="ach-{{ $achievement['id'] }}"
                                @class([
                                    'flex items-start gap-3 rounded-xl p-2.75',
                                    'border border-accent/25 bg-accent-tint/5' => $achievement['unlocked'],
                                    'border border-line bg-ground' => ! $achievement['unlocked'],
                                ])
                            >
                                {{-- The badge is the one thing on this page that
                                     needs the network: it is served from
                                     RetroAchievements' CDN and only its name is
                                     stored. Offline the card is correct and the
                                     picture is broken. --}}
                                <div @class([
                                    'grid size-9.5 shrink-0 place-items-center overflow-hidden rounded-lg border',
                                    'border-accent/40 bg-accent-tint/10' => $achievement['unlocked'],
                                    'border-line-input bg-raised opacity-60' => ! $achievement['unlocked'],
                                ])>
                                    @if ($achievement['badge'] !== null)
                                        <img src="{{ $achievement['badge'] }}" alt="" loading="lazy" class="size-full object-cover" />
                                    @else
                                        <flux:icon.trophy class="size-4 text-fg-faint" />
                                    @endif
                                </div>

                                <div class="min-w-0 flex-1">
                                    <div class="flex items-baseline gap-2">
                                        <p class="truncate text-sm text-fg-bright" title="{{ $achievement['title'] }}">{{ $achievement['title'] }}</p>
                                        <span @class([
                                            'ml-auto shrink-0 font-mono text-xs',
                                            'text-accent' => $achievement['unlocked'],
                                            'text-fg-dim' => ! $achievement['unlocked'],
                                        ])>{{ $achievement['points'] }}</span>
                                    </div>

                                    @if ($achievement['description'] !== null)
                                        <p class="mt-0.75 text-xs leading-snug text-fg-muted">{{ $achievement['description'] }}</p>
                                    @endif

                                    <div class="mt-1.75 flex flex-wrap items-center gap-2">
                                        <span class="font-mono text-[10px] text-fg-dim">{{ $achievement['footnote'] }}</span>
                                        @if ($achievement['hardcore'])
                                            <span class="kicker text-[9px] text-accent">{{ __('Hardcore') }}</span>
                                        @endif
                                        @if ($achievement['kind'] !== null)
                                            <span class="kicker text-[9px] text-fg-faint">{{ $achievement['kind'] }}</span>
                                        @endif
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>
    @endif

    @if ($this->activeTab === 'files')
        <section class="relative z-1 px-4 pt-4.5 lg:px-8">
            <div class="overflow-hidden rounded-xl border border-line bg-sunken">
                <div class="flex flex-wrap items-center gap-x-3.5 gap-y-2 border-b border-raised px-4.5 py-3.75">
                    {{-- The console's folder, not a file's: each row carries its own
                         subfolder, since one game can straddle several. --}}
                    <span class="font-mono text-xs break-all text-fg-dim">{{ $console?->libraryPath() ?? '—' }}</span>
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
    @endif

    @if ($this->activeTab === 'artwork')
        <section class="relative z-1 px-4 pt-4.5 lg:px-8">
            <div class="overflow-hidden rounded-xl border border-line bg-sunken">
                {{-- Fetching another region is additive: what is here stays,
                     and the group below is what chooses between them. --}}
                <div class="flex flex-wrap items-end gap-3 border-b border-raised px-4.5 py-3.75">
                    <div class="min-w-0">
                        <p class="kicker mb-1.5 text-fg-faint">{{ __('Fetch another region') }}</p>
                        <p class="max-w-[64ch] text-xs text-fg-faint">
                            {{ __('Downloads that region\'s copies alongside the ones already here. A type that region has nothing for is left as it is.') }}
                        </p>
                    </div>

                    <div class="ml-auto flex flex-wrap items-center gap-2">
                        <flux:select wire:model="fetchRegion" size="sm" class="min-w-44">
                            <flux:select.option value="">{{ __('Choose a region') }}</flux:select.option>
                            @foreach ($this->fetchableRegions as $code => $label)
                                <flux:select.option value="{{ $code }}">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:button size="sm" variant="filled" type="button" wire:click="fetchRegionMedia">
                            {{ __('Fetch') }}
                        </flux:button>
                    </div>
                </div>

                @foreach ($this->galleryByRegion as $group)
                    <div wire:key="region-{{ $group['region'] ?? 'none' }}" class="border-b border-raised last:border-0">
                        <div class="flex flex-wrap items-center gap-2.5 px-4.5 pt-3.5">
                            @if ($group['icon'] !== null)
                                <img src="{{ $group['icon'] }}" alt="" class="h-4.5 w-auto border border-line-input" />
                            @endif

                            <p class="text-sm text-fg-bright">{{ $group['label'] }}</p>
                            <span class="font-mono text-xs text-fg-dim">{{ count($group['images']) }}</span>

                            {{-- Region-less artwork cannot be chosen: there is
                                 no other copy of it to choose instead. --}}
                            @if ($group['region'] !== null)
                                @if ($group['selected'])
                                    <span class="kicker ml-auto rounded-md border border-accent/40 bg-accent-tint/10 px-1.75 py-0.75 text-accent">
                                        {{ $game->media_region === null ? __('Shown · from settings') : __('Shown') }}
                                    </span>
                                @else
                                    <flux:button class="ml-auto" size="xs" variant="ghost" type="button"
                                                 wire:click="useRegion('{{ $group['region'] }}')">
                                        {{ __('Show this region') }}
                                    </flux:button>
                                @endif
                            @endif
                        </div>

                        <div class="flex flex-wrap gap-3 px-4.5 py-3.5">
                            @foreach ($group['images'] as ['key' => $key, 'src' => $src, 'caption' => $caption])
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
                @endforeach

                @if ($game->media_region !== null)
                    <div class="border-t border-raised px-4.5 py-3">
                        <flux:button size="xs" variant="ghost" type="button" wire:click="useRegion(null)">
                            {{ __('Follow the library setting instead') }}
                        </flux:button>
                    </div>
                @endif
            </div>
        </section>
    @endif
</x-lightbox>
