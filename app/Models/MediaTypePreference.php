<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Whether a provider media type is fetched automatically after a match.
 *
 * One row per raw ScreenScraper type, so turning on videos or manuals is a
 * checkbox rather than a deploy.
 *
 * @property int $id
 * @property string $media_type
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['media_type', 'enabled'])]
class MediaTypePreference extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    /** @param  Builder<MediaTypePreference>  $query */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('enabled', true);
    }

    /**
     * The raw types to fetch, as a plain list.
     *
     * @return array<int, string>
     */
    public static function enabledTypes(): array
    {
        return static::query()->enabled()->pluck('media_type')->all();
    }
}
