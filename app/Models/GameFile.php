<?php

namespace App\Models;

use App\Enums\FileRole;
use Database\Factories\GameFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A file on disk that belongs to a game.
 *
 * Deliberately wider than "ROM": a .cue, a .bin track and an .m3u playlist are
 * none of them ROMs, but all of them are part of the game and all of them
 * should show in the library. retroBite only ever reads these — nothing here
 * is moved, copied or written.
 *
 * Carries facts, never state. Whether a game has been identified is recorded
 * once, on the game.
 *
 * @property int $id
 * @property int $game_id
 * @property string $path relative to the library root, never absolute
 * @property string $filename
 * @property string $extension
 * @property int|null $size_bytes
 * @property string|null $crc
 * @property string|null $md5
 * @property string|null $sha1
 * @property Carbon|null $hashed_at
 * @property string|null $ra_hash
 * @property int|null $ra_hash_size
 * @property int|null $ra_hash_mtime
 * @property Carbon|null $ra_hashed_at
 * @property FileRole $role
 * @property int|null $disc_number
 * @property string|null $region
 * @property int|null $parent_id
 * @property Carbon|null $missing_since
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Game $game
 * @property-read GameFile|null $parent
 * @property-read Collection<int, GameFile> $children
 */
#[Fillable([
    'game_id', 'path', 'filename', 'extension', 'size_bytes', 'crc', 'md5',
    'sha1', 'hashed_at', 'role', 'disc_number', 'region', 'parent_id',
    'missing_since', 'ra_hash', 'ra_hash_size', 'ra_hash_mtime', 'ra_hashed_at',
])]
class GameFile extends Model
{
    /** @use HasFactory<GameFileFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => FileRole::class,
            'size_bytes' => 'integer',
            'disc_number' => 'integer',
            'hashed_at' => 'datetime',
            'ra_hash_size' => 'integer',
            'ra_hash_mtime' => 'integer',
            'ra_hashed_at' => 'datetime',
            'missing_since' => 'datetime',
        ];
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * The sheet or playlist that names this file.
     *
     * @return BelongsTo<GameFile, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(GameFile::class, 'parent_id');
    }

    /**
     * The tracks or discs this file names.
     *
     * @return HasMany<GameFile, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(GameFile::class, 'parent_id');
    }

    /** Whether a checksum has been computed for this file yet. */
    public function isHashed(): bool
    {
        return $this->md5 !== null || $this->sha1 !== null || $this->crc !== null;
    }

    /**
     * Whether the file was present at the last scan.
     *
     * Named isPresent rather than present so it cannot be confused with the
     * query scope of that name — Eloquent resolves a static call to the
     * instance method first, and the two would silently shadow each other.
     */
    public function isPresent(): bool
    {
        return $this->missing_since === null;
    }

    /**
     * Files the provider can actually identify.
     *
     * @param  Builder<GameFile>  $query
     */
    public function scopeIdentifiable(Builder $query): void
    {
        $query->whereIn('role', array_map(
            fn (FileRole $role) => $role->value,
            array_filter(FileRole::cases(), fn (FileRole $role) => $role->identifiable()),
        ));
    }

    /** @param  Builder<GameFile>  $query */
    public function scopePresent(Builder $query): void
    {
        $query->whereNull('missing_since');
    }

    /** @param  Builder<GameFile>  $query */
    public function scopeMissing(Builder $query): void
    {
        $query->whereNotNull('missing_since');
    }

    /** @param  Builder<GameFile>  $query */
    public function scopeUnhashed(Builder $query): void
    {
        $query->whereNull('md5')->whereNull('sha1')->whereNull('crc');
    }
}
