<?php

namespace App\Models;

use App\Enums\MediaKind;
use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Artwork retroBite downloaded and owns.
 *
 * The opposite of a File in every way that matters: this did not exist until
 * the platform fetched it, it lives in storage the platform controls, and it
 * can be deleted and fetched again without losing anything.
 *
 * @property int $id
 * @property int $game_id
 * @property string $screenscraper_type the provider's raw type, e.g. box-2D
 * @property string|null $region
 * @property string $md5
 * @property string $path
 * @property string $extension
 * @property int|null $size_bytes
 * @property string|null $source_url credentials stripped
 * @property Carbon|null $downloaded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Game $game
 */
#[Fillable([
    'game_id', 'screenscraper_type', 'region', 'md5', 'path', 'extension',
    'size_bytes', 'source_url', 'downloaded_at',
])]
class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory;

    /** Laravel would otherwise look for a "medias" table. */
    protected $table = 'media';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'downloaded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /** Below this an image is a thumbnail, whatever the provider filed it as. */
    private const WALLPAPER_MIN_BYTES = 50_000;

    /**
     * Artwork fit to fill a whole screen.
     *
     * Two things disqualify a row. The provider files in-game screenshots as
     * backdrops beside real wallpaper, and a screenshot blown up behind a form
     * reads as a mistake; and some of what it does call key art is thumbnail
     * sized, which full-bleed is the least forgiving place to discover.
     *
     * @param  Builder<Media>  $query
     */
    public function scopeWallpaper(Builder $query): void
    {
        $query->whereIn('screenscraper_type', MediaKind::Backdrop->keyArtTypes())
            ->where('size_bytes', '>=', self::WALLPAPER_MIN_BYTES);
    }

    /**
     * Narrow to the types a display role covers, best first.
     *
     * The storage is raw provider types; MediaKind is only how the interface
     * asks for "the cover" without knowing whether that turned out to be a
     * box-2D or a box-3D.
     *
     * @param  Builder<Media>  $query
     */
    public function scopeOfKind(Builder $query, MediaKind $kind): void
    {
        $types = $kind->screenScraperTypes();

        $query->whereIn('screenscraper_type', $types)
            // Preserve the enum's preference order rather than falling back to
            // whatever the database happens to return first.
            ->orderByRaw(
                'FIELD(screenscraper_type'.str_repeat(', ?', count($types)).')',
                $types,
            );
    }
}
