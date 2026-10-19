<?php

namespace App\Models;

use App\Enums\FileArchiveReason;
use App\Enums\FileContent;
use App\Enums\FileRole;
use App\Support\RetroAchievements\FileFingerprint;
use Database\Factories\GameFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
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
 * A row outlives its file. A file removed on purpose — converted with its
 * source not kept, decrypted in place, deleted on the game page, pruned — is
 * archived (soft-deleted, with archived_reason), not deleted: the row is the
 * only record of which dump the file was. A file made out of another points
 * at it (source_id), so a converted copy the provider has never seen is still
 * known by the dump it came from (docs/adr/0009). Deleting the game deletes
 * them for good.
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
 * @property FileRole $role how the file is built: an image, a cuesheet, a track, a playlist
 * @property FileContent|null $content_type what it holds: a game, DLC or an update; null until its name or its disc says
 * @property int|null $disc_number
 * @property string|null $region
 * @property int|null $scrapes how many times the provider has had this very dump scraped; null for one it does not know
 * @property list<string>|null $provider_flags the provider's flags for the dump: beta, demo, proto, trad, hack, unl, alt, best
 * @property int|null $parent_id
 * @property int|null $source_id the file this one was made out of, by a conversion or decryption
 * @property Carbon|null $missing_since
 * @property Carbon|null $deleted_at when the file was archived
 * @property FileArchiveReason|null $archived_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Game $game
 * @property-read GameFile|null $parent
 * @property-read Collection<int, GameFile> $children
 * @property-read GameFileMeta|null $meta what the toolbox read off the disc; null until something has
 * @property-read GameFile|null $source
 * @property-read Collection<int, GameFile> $derivatives
 */
#[Fillable([
    'game_id', 'path', 'filename', 'extension', 'size_bytes', 'crc', 'md5',
    'sha1', 'hashed_at', 'role', 'content_type', 'disc_number', 'region', 'scrapes', 'provider_flags',
    'parent_id', 'source_id', 'missing_since', 'archived_reason', 'ra_hash', 'ra_hash_size',
    'ra_hash_mtime', 'ra_hashed_at',
])]
class GameFile extends Model
{
    /** @use HasFactory<GameFileFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => FileRole::class,
            'content_type' => FileContent::class,
            'size_bytes' => 'integer',
            'disc_number' => 'integer',
            'scrapes' => 'integer',
            'provider_flags' => 'array',
            'hashed_at' => 'datetime',
            'ra_hash_size' => 'integer',
            'ra_hash_mtime' => 'integer',
            'ra_hashed_at' => 'datetime',
            'missing_since' => 'datetime',
            'archived_reason' => FileArchiveReason::class,
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

    /**
     * The file this one was made out of, archived or not.
     *
     * @return BelongsTo<GameFile, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(GameFile::class, 'source_id')->withTrashed();
    }

    /**
     * The files made out of this one.
     *
     * @return HasMany<GameFile, $this>
     */
    public function derivatives(): HasMany
    {
        return $this->hasMany(GameFile::class, 'source_id');
    }

    /**
     * Archive the row: the file is gone from the library on purpose, and the
     * row stays as the record of which dump it was.
     */
    public function archive(FileArchiveReason $reason): void
    {
        // Saved first: a soft delete writes deleted_at alone.
        $this->forceFill(['archived_reason' => $reason])->save();
        $this->delete();
    }

    /**
     * The file and the files it was made out of, nearest first. Bounded, and
     * safe against a loop, which only a hand-edited row could make.
     *
     * @return list<GameFile>
     */
    public function lineage(int $depth = 5): array
    {
        $chain = [$this];
        $seen = [$this->id => true];
        $file = $this;

        while (count($chain) <= $depth && $file->source_id !== null && ! isset($seen[$file->source_id])) {
            $file = $file->source;

            if ($file === null) {
                break;
            }

            $seen[$file->id] = true;
            $chain[] = $file;
        }

        return $chain;
    }

    /**
     * The file the provider would know this one as: the first of the files it
     * was made out of — a Redump image before it was compressed or decrypted
     * — or, where that is a sheet, its first track, which is what the
     * provider indexes. A track goes by its sheet's. This file itself when
     * it was made out of nothing.
     */
    public function origin(): GameFile
    {
        if ($this->source_id === null && $this->parent?->source_id !== null) {
            return $this->parent->origin();
        }

        $chain = $this->lineage();
        $first = $chain[count($chain) - 1];

        if ($first->role !== FileRole::Sheet || $first->is($this)) {
            return $first;
        }

        return $first->children()->withTrashed()->orderBy('path')->first() ?? $first;
    }

    /**
     * What the toolbox read off the disc: serial, video mode, encryption, disc key.
     *
     * @return HasOne<GameFileMeta, $this>
     */
    public function meta(): HasOne
    {
        return $this->hasOne(GameFileMeta::class);
    }

    /**
     * Keep disc facts on record, merging them into what is there already.
     *
     * @param  array{license_id?: string|null, video_mode?: string|null, encrypted?: bool|null, disc_key?: string|null}  $facts
     */
    public function rememberMeta(array $facts): GameFileMeta
    {
        $meta = $this->meta()->updateOrCreate([], $facts);
        $this->setRelation('meta', $meta);

        return $meta;
    }

    /** Whether a checksum has been computed for this file yet. */
    public function isHashed(): bool
    {
        return $this->md5 !== null || $this->sha1 !== null || $this->crc !== null;
    }

    /** Whether RAHasher has been given this file, hashing it or failing to: scopeNeverRaHashed's opposite. */
    public function raHashAttempted(): bool
    {
        return $this->ra_hash !== null || $this->ra_hash_size !== null;
    }

    /**
     * Keep what RAHasher made of this file, a hash or null for a failure,
     * against the fingerprint it was taken at — so neither is read again until
     * the file changes.
     */
    public function rememberRaHash(?string $hash, FileFingerprint $fingerprint): void
    {
        $this->update([
            'ra_hash' => $hash,
            'ra_hash_size' => $fingerprint->size,
            'ra_hash_mtime' => $fingerprint->mtime,
            'ra_hashed_at' => now(),
        ]);
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

    /**
     * Files RAHasher has never been given: no RA hash, and no failed attempt
     * on record — a failure keeps the fingerprint of the file it failed on.
     *
     * @param  Builder<GameFile>  $query
     */
    public function scopeNeverRaHashed(Builder $query): void
    {
        $query->whereNull('ra_hash')->whereNull('ra_hash_size');
    }

    /**
     * The files RAHasher could be given, the one it is given first
     * (Game::hashableFile()): a playlist, then a sheet, then an image, by disc.
     *
     * @param  Builder<GameFile>  $query
     */
    public function scopeHashableFirst(Builder $query): void
    {
        $query->present()
            ->whereIn('role', [FileRole::Playlist->value, FileRole::Sheet->value, FileRole::Rom->value])
            ->orderByRaw(
                'CASE role WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END',
                [FileRole::Playlist->value, FileRole::Sheet->value],
            )
            ->orderByRaw('disc_number IS NULL, disc_number')
            ->orderBy('id');
    }

    /**
     * Forget the failed RA hash attempts, so those files count as never tried.
     * For when what changed is not the file: a new RAHasher, or being told to.
     * Only those of these games, given some: the rest keep theirs.
     *
     * @param  iterable<int>|null  $gameIds
     * @return int how many were forgotten
     */
    public static function forgetFailedRaHashes(?string $console = null, ?iterable $gameIds = null): int
    {
        return static::query()
            ->whereNull('ra_hash')
            ->whereNotNull('ra_hash_size')
            ->when($console !== null, function (Builder $query) use ($console): void {
                $query->onConsole((string) $console);
            })
            ->when($gameIds !== null, function (Builder $query) use ($gameIds): void {
                $query->whereIn('game_id', [...$gameIds]);
            })
            ->update(['ra_hash_size' => null, 'ra_hash_mtime' => null, 'ra_hashed_at' => null]);
    }

    /**
     * Files no console toolbox has read yet.
     *
     * Only license_id is tested: it is the one field every toolbox that runs at
     * all comes back with, and a disc with no video mode is ordinary.
     *
     * @param  Builder<GameFile>  $query
     */
    public function scopeUninspected(Builder $query): void
    {
        $query->whereDoesntHave('meta', function (Builder $meta): void {
            $meta->whereNotNull('license_id');
        });
    }

    /**
     * The files of one console's games, by its key.
     *
     * @param  Builder<GameFile>  $query
     */
    public function scopeOnConsole(Builder $query, string $console): void
    {
        $query->whereIn('game_id', Game::query()->forConsole($console)->select('id'));
    }
}
