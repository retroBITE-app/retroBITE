<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A setting a person can change while the app is running.
 *
 * Distinct from config/, which is redeployed rather than toggled. Anything
 * that belongs in a settings screen belongs here; anything an operator sets
 * once in .env does not.
 *
 * @property int $id
 * @property string $key
 * @property mixed $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['key', 'value'])]
class AppSetting extends Model
{
    /** Queue a media scrape automatically after a successful match. */
    public const AUTO_QUEUE_MEDIA_SCRAPE = 'auto_queue_media_scrape';

    /**
     * Which regional copy of a piece of artwork to keep.
     *
     * Empty means no preference, which falls back to the neutral entries first.
     */
    public const MEDIA_REGION = 'media_region';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::query()->where('key', $key)->first();

        if ($setting === null) {
            return $default;
        }

        return $setting->value ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function enabled(string $key, bool $default = false): bool
    {
        return (bool) static::get($key, $default);
    }
}
