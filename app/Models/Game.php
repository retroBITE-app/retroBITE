<?php

namespace App\Models;

use App\Enums\FileRole;
use App\Enums\GameStatus;
use App\Support\Console;
use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
