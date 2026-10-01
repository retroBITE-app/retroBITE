<?php

namespace App\Models;

use App\Enums\FileRole;
use App\Enums\GameStatus;
use App\Enums\MediaKind;
use App\Enums\RetroAchievementsStatus;
use App\Support\Console;
use App\Support\MediaRegions;
use App\Support\MediaTypes;
use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A game in the library.
 *
 * Created the moment a file is found, long before anyone knows what it is —
 * so the interface can show the library filling up while identification runs
 * behind it. Its identity becomes canonical only when screenscraper_id is set.
 *
 * @property int $id
 * @property int|null $screenscraper_id
 * @property int|null $retroachievements_id
 * @property string $console
 * @property string $title
 * @property string $slug
 * @property GameStatus $status
 * @property RetroAchievementsStatus $retroachievements_status
 * @property string|null $description
 * @property string|null $release_date
 * @property string|null $genre
 * @property string|null $players
 * @property string|null $publisher
 * @property string|null $developer
 * @property string|null $region
 * @property int|null $rating
 * @property string|null $media_region
 * @property Carbon|null $matched_at
 * @property Carbon|null $dumps_recorded_at when the provider's word on each of its files' dumps was last recorded (ProviderDumps)
 * @property Carbon|null $retroachievements_matched_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, GameFile> $files
 * @property-read Collection<int, Media> $media
 * @property-read MediaList|null $mediaList
 */
#[Fillable([
    'screenscraper_id', 'console', 'title', 'slug', 'status', 'description',
    'release_date', 'genre', 'players', 'publisher', 'developer', 'region',
    'rating', 'media_region',
    'matched_at', 'retroachievements_id', 'retroachievements_status',
    'retroachievements_matched_at',
])]
class Game extends Model
{
    /** @use HasFactory<GameFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GameStatus::class,
            'matched_at' => 'datetime',
            'dumps_recorded_at' => 'datetime',
            'rating' => 'integer',
            'retroachievements_status' => RetroAchievementsStatus::class,
            'retroachievements_matched_at' => 'datetime',

            // Not columns on this table. The library list selects these off
            // the joined ra_progress row, and without a cast they arrive as
            // PDO strings — "31" formats fine and compares wrong.
            'ra_unlocked' => 'integer',
            'ra_unlocked_hardcore' => 'integer',
            'ra_achievements_possible' => 'integer',
            'ra_points' => 'integer',
            'ra_points_hardcore' => 'integer',
            'ra_points_possible' => 'integer',
        ];
    }

    /** @return HasMany<GameFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(GameFile::class);
    }

    /** @return HasMany<Media, $this> */
    public function media(): HasMany
    {
        return $this->hasMany(Media::class);
    }

    /**
     * What games.show needs to address this game: its console and its slug.
     *
     * The game's own route key is still its id, so this is spelled out rather
     * than left to route() — handed a bare model, route() would put the id in
     * the {console} segment.
     *
     * @return array{console: string, game: string}
     */
    public function routeParameters(): array
    {
        return ['console' => $this->console, 'game' => $this->slug];
    }

    /** @return HasOne<MediaList, $this> */
    public function mediaList(): HasOne
    {
        return $this->hasOne(MediaList::class);
    }

    /**
     * Keep the artwork list out of a provider answer, replacing any older one.
     *
     * Called by everything that has just paid for a jeuInfos answer, so that
     * nothing after it has to pay again to learn what artwork there is.
     *
     * @param  array<int, array<string, mixed>>  $medias
     */
    public function rememberMediaList(array $medias): void
    {
        $this->mediaList()->updateOrCreate([], ['medias' => $medias, 'fetched_at' => now()]);
    }

    /**
     * One piece of artwork of a kind, in the enum's preference order.
     *
     * Reads the relation rather than querying, so an eager-loaded page asks for
     * a cover, a logo and a backdrop without three more round trips.
     *
     * Region decides before size does. A game can hold the same cover from
     * four regions at once — that is the point of being able to fetch another
     * one — and without this the biggest file wins, which is how somebody who
     * asked for the Japanese box keeps being shown the European one.
     */
    public function artwork(MediaKind $kind): ?Media
    {
        return $this->artworkOfTypes($kind->screenScraperTypes());
    }

    /**
     * The same choice over any list of provider media types, most preferred
     * first — for a caller that wants artwork no MediaKind names, such as a
     * transfer target's box back or manual.
     *
     * @param  array<int, string>  $types
     */
    public function artworkOfTypes(array $types): ?Media
    {
        $order = array_flip($types);
        $regions = array_flip(MediaRegions::chainFor($this->media_region));

        return $this->media
            ->filter(fn (Media $media) => Arr::has($order, $media->screenscraper_type))
            ->sortBy([
                fn (Media $a, Media $b) => Arr::get($order, $a->screenscraper_type) <=> Arr::get($order, $b->screenscraper_type),
                fn (Media $a, Media $b) => $this->regionRank($a, $regions) <=> $this->regionRank($b, $regions),
                fn (Media $a, Media $b) => (int) $b->size_bytes <=> (int) $a->size_bytes,
            ])
            ->first();
    }

    /**
     * How far down the chain this artwork's region sits.
     *
     * A region nobody named still beats nothing at all — an Italian cover is
     * better than no cover — so it ranks last rather than being dropped.
     *
     * @param  array<string, int>  $regions
     */
    private function regionRank(Media $media, array $regions): int
    {
        return $regions[$media->region] ?? count($regions);
    }

    /**
     * The achievement set this game was identified as, if any.
     *
     * @return BelongsTo<RaGame, $this>
     */
    public function raGame(): BelongsTo
    {
        return $this->belongsTo(RaGame::class, 'retroachievements_id');
    }

    /**
     * Everyone's progress in this game's set.
     *
     * Keyed through retroachievements_id rather than this game's own id, which
     * is the whole point of the set owning progress: merging this game into
     * another, or rebuilding the library, leaves the rows where they are.
     *
     * @return HasMany<RaProgress, $this>
     */
    public function raProgress(): HasMany
    {
        return $this->hasMany(RaProgress::class, 'ra_game_id', 'retroachievements_id');
    }

    /** @return BelongsToMany<GameCollection, $this> */
    public function collections(): BelongsToMany
    {
        // Named explicitly: the convention here would be game_game_collection,
        // which reads like a mistake.
        return $this->belongsToMany(GameCollection::class, 'game_collection_game')
            ->withPivot('position')
            ->withTimestamps();
    }

    /**
     * The console this game belongs to, as a value object.
     *
     * Not a relation: consoles live in config, not in a table.
     */
    public function console(): ?Console
    {
        return Console::tryFrom($this->console);
    }

    /**
     * The one file worth asking the provider about.
     *
     * A multi-disc game is a playlist, four cuesheets and four tracks. Every
     * disc answers to the same provider id, so one lookup settles all of them
     * and the other eight requests would buy nothing — at a tenfold penalty
     * whenever one misses.
     *
     * A whole image is preferred over a track, and the lowest disc over a
     * later one, because disc 1 is the entry the provider is most likely to
     * hold.
     */
    public function identifiableFile(): ?GameFile
    {
        return $this->files()
            ->identifiable()
            ->present()
            ->orderByRaw('CASE WHEN role = ? THEN 0 ELSE 1 END', [FileRole::Rom->value])
            ->orderByRaw('disc_number IS NULL, disc_number')
            ->orderBy('id')
            ->first();
    }

    /**
     * The serial the disc names itself by, e.g. SLES_503.86.
     *
     * Taken from the file the provider would be asked about, which is disc 1 of
     * a multi-disc set — the later discs carry serials of their own, and a game
     * has only one name.
     */
    public function licenseId(): ?string
    {
        return $this->identifiableFile()?->license_id;
    }

    /**
     * The one file to give RAHasher.
     *
     * Deliberately not identifiableFile(). That one prefers a track, which is
     * right for ScreenScraper because a track is what ScreenScraper's database
     * holds — and wrong here, because RAHasher needs the container: it reads
     * SYSTEM.CNF out of a cuesheet's disc to find the executable it actually
     * hashes, and a loose .bin gives it nothing to read.
     *
     * One file settles the whole game. A set lists a hash for every disc, so
     * disc one recognises a four-disc title, and hashing the other three would
     * cost minutes each to learn the same thing.
     */
    public function hashableFile(): ?GameFile
    {
        return $this->files()
            ->present()
            ->whereIn('role', [FileRole::Playlist->value, FileRole::Sheet->value, FileRole::Rom->value])
            ->orderByRaw(
                'CASE role WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END',
                [FileRole::Playlist->value, FileRole::Sheet->value],
            )
            ->orderByRaw('disc_number IS NULL, disc_number')
            ->orderBy('id')
            ->first();
    }

    /**
     * Why this game cannot be put to the provider, or null when it can.
     *
     * Returned as a reason rather than a boolean so the interface can say what
     * is wrong instead of offering a button that quietly does nothing.
     */
    public function blockedFromLookup(): ?string
    {
        if ($this->status === GameStatus::Matched) {
            return __('Already identified.');
        }

        $console = $this->console();

        if ($console === null || $console->screenscraperId === null) {
            return __('This console is not mapped to ScreenScraper.');
        }

        // The list view selects this count so a page of cards does not run a
        // files query per row; everywhere else falls back to asking directly.
        $hasIdentifiable = array_key_exists('identifiable_files_count', $this->attributes)
            ? (int) $this->attributes['identifiable_files_count'] > 0
            : $this->identifiableFile() !== null;

        if (! $hasIdentifiable) {
            // A playlist and its cuesheets with no data track left, or every
            // file gone missing since the last scan.
            return __('No file here is one the provider can identify.');
        }

        return null;
    }

    /** Why a hand-picked match cannot be offered; a matched or unhashable game still can, it is what the pick is for. */
    public function blockedFromManualLookup(): ?string
    {
        $console = $this->console();

        if ($console === null || $console->screenscraperId === null) {
            return __('This console is not mapped to ScreenScraper.');
        }

        return null;
    }

    public function canBeIdentified(): bool
    {
        return $this->blockedFromLookup() === null;
    }

    /**
     * Why artwork cannot be fetched for this game, or null when it can.
     *
     * Two separate conditions, and the second is not about this game at all:
     * with no media type switched on the job has nothing to ask for and would
     * return having done nothing.
     */
    public function blockedFromMediaScrape(): ?string
    {
        if ($this->screenscraper_id === null) {
            return __('Identify the game first — artwork is fetched by provider id.');
        }

        if (MediaTypes::enabled() === []) {
            return __('No media types are switched on. Choose some in Settings → Media.');
        }

        return null;
    }

    public function canFetchMedia(): bool
    {
        return $this->blockedFromMediaScrape() === null;
    }

    /**
     * Why this game cannot be asked about its rating, or null when it can.
     *
     * Shorter than the media gate on purpose: a rating rides along in the same
     * answer as everything else, so there is nothing to switch on and nothing
     * to choose. The provider id is the whole requirement — it is what the
     * lookup is keyed by.
     *
     * Holding a rating already is not a reason to refuse. Ratings are votes and
     * they accumulate, so asking again is a real question with a possibly
     * different answer, which is why the menu offers it rather than greying out.
     */
    public function blockedFromRating(): ?string
    {
        if ($this->screenscraper_id === null) {
            return __('Identify the game first — the rating comes back by provider id.');
        }

        return null;
    }

    public function canBeRated(): bool
    {
        return $this->blockedFromRating() === null;
    }

    /** @param  Builder<Game>  $query */
    public function scopeAwaitingLookup(Builder $query): void
    {
        $query->where('status', GameStatus::Placeholder);
    }

    /**
     * Games worth trying RetroAchievements on again.
     *
     * Includes NoMatch, unlike scopeAwaitingLookup: there is no scarce failed
     * lookup allowance here, the hash is already cached, and new sets appear
     * constantly.
     *
     * @param  Builder<Game>  $query
     */
    public function scopeAwaitingRetroAchievements(Builder $query): void
    {
        $query->whereIn('retroachievements_status', [
            RetroAchievementsStatus::Pending->value,
            RetroAchievementsStatus::NoMatch->value,
        ]);
    }

    /**
     * Games identified before ratings existed.
     *
     * The backfill's whole selection. Keyed off the rating being absent rather
     * than off a timestamp, so a game the provider had no rating for last time
     * is asked again — the votes are theirs and they accumulate. A game with
     * no screenscraper_id is skipped because there is nothing to ask about.
     *
     * @param  Builder<Game>  $query
     */
    public function scopeAwaitingRating(Builder $query): void
    {
        $query->whereNotNull('games.screenscraper_id')->whereNull('games.rating');
    }

    /**
     * Games identified before the provider's word on each dump was recorded.
     *
     * Off a timestamp rather than the files' columns: a game none of whose
     * dumps the provider knows has been asked, and has nothing to show for it.
     *
     * @param  Builder<Game>  $query
     */
    public function scopeAwaitingDumps(Builder $query): void
    {
        $query->whereNotNull('games.screenscraper_id')->whereNull('games.dumps_recorded_at');
    }

    /**
     * Whether the provider offers artwork of a switched-on type this game
     * does not hold yet.
     *
     * Judged against the list kept from the provider's last answer, not
     * against the settings alone. The provider does not hold every type for
     * every game, and a game short of a logo that does not exist would
     * otherwise be asked about for ever; the list says which gaps can be
     * filled. A game with no list says nothing either way, so it counts once:
     * its fetch asks the provider and keeps the answer.
     *
     * Reads the loaded relations, so a console's worth can be judged from one
     * eager-loaded query.
     *
     * @param  array<int, string>  $wanted
     */
    public function lacksMedia(array $wanted): bool
    {
        if ($this->media->isEmpty() || $this->mediaList === null) {
            return true;
        }

        $offered = array_column((array) $this->mediaList->medias, 'type');
        $held = $this->media->pluck('screenscraper_type')->all();

        return array_diff(array_intersect($wanted, $offered), $held) !== [];
    }

    /**
     * Games whose title holds the term anywhere, the term taken literally.
     *
     * @param  Builder<Game>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        // Qualified, for the joined library list, as forConsole below.
        $query->where('games.title', 'like', '%'.self::escapeLike($term).'%');
    }

    /** A term for LIKE with its % and _ meaning themselves, not "anything". */
    public static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    /**
     * @param  Builder<Game>  $query
     */
    public function scopeForConsole(Builder $query, string $console): void
    {
        // Qualified: the library list joins ra_progress, and an unqualified
        // column name in a joined query is one added column away from being
        // ambiguous at runtime.
        $query->where('games.console', $console);
    }
}
