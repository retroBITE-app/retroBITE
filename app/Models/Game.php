<?php

namespace App\Models;

use App\Enums\FileRole;
use App\Enums\GameStatus;
use App\Enums\MediaKind;
use App\Support\Console;
use App\Support\MediaTypes;
use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property string $console
 * @property string $title
 * @property string $slug
 * @property GameStatus $status
 * @property string|null $description
 * @property string|null $release_date
 * @property string|null $genre
 * @property string|null $players
 * @property string|null $publisher
 * @property string|null $developer
 * @property string|null $region
 * @property Carbon|null $matched_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, GameFile> $files
 * @property-read Collection<int, Media> $media
 */
#[Fillable([
    'screenscraper_id', 'console', 'title', 'slug', 'status', 'description',
    'release_date', 'genre', 'players', 'publisher', 'developer', 'region',
    'matched_at',
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
     * One piece of artwork of a kind, in the enum's preference order.
     *
     * Reads the relation rather than querying, so an eager-loaded page asks for
     * a cover, a logo and a backdrop without three more round trips. Two of the
     * same type break the tie on file size: the provider holds the same picture
     * at several resolutions, and the biggest is the one worth showing.
     */
    public function artwork(MediaKind $kind): ?Media
    {
        $order = array_flip($kind->screenScraperTypes());

        return $this->media
            ->filter(fn (Media $media) => Arr::has($order, $media->screenscraper_type))
            ->sortBy([
                fn (Media $a, Media $b) => Arr::get($order, $a->screenscraper_type) <=> Arr::get($order, $b->screenscraper_type),
                fn (Media $a, Media $b) => (int) $b->size_bytes <=> (int) $a->size_bytes,
            ])
            ->first();
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

        if ($this->identifiableFile() === null) {
            // A playlist and its cuesheets with no data track left, or every
            // file gone missing since the last scan.
            return __('No file here is one the provider can identify.');
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

    /** @param  Builder<Game>  $query */
    public function scopeAwaitingLookup(Builder $query): void
    {
        $query->where('status', GameStatus::Placeholder);
    }

    /** @param  Builder<Game>  $query */
    public function scopeForConsole(Builder $query, string $console): void
    {
        $query->where('console', $console);
    }
}
