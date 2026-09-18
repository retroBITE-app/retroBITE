<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
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

    /** The provider media types to fetch. Absent means the shipped selection. */
    public const MEDIA_TYPES = 'media_types';

    /** The CRT scanline overlay over key art and the sign-in backdrop. */
    public const UI_SCANLINES = 'ui_scanlines';

    /**
     * How the library lists games, 'cards' or 'table'.
     *
     * Remembered app-wide rather than per page: somebody who prefers the table
     * prefers it on every console's shelf as well.
     */
    public const UI_GAMES_VIEW = 'ui_games_view';

    /**
     * What a key means before anybody has set it.
     *
     * Here rather than at each call site: the auto-queue default was spelled
     * out in four places, and the fourth one to disagree would have won.
     *
     * @var array<string, mixed>
     */
    private const DEFAULTS = [
        self::AUTO_QUEUE_MEDIA_SCRAPE => true,
        self::MEDIA_REGION => '',
        self::UI_SCANLINES => true,
        self::UI_GAMES_VIEW => 'cards',
    ];

    /**
     * Values already read this request.
     *
     * A settings table is read far more often than written — the scanline
     * overlay alone is asked about four times a page — and every read was a
     * fresh query.
     *
     * @var array<string, mixed>
     */
    protected static array $memo = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $default ??= Arr::get(self::DEFAULTS, $key);

        if (! array_key_exists($key, static::$memo)) {
            // ->first()?->value rather than ->value('value'): the latter skips
            // the json cast and would memoise the raw string.
            static::$memo[$key] = static::query()->where('key', $key)->first()?->value;
        }

        return static::$memo[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        // Written through rather than invalidated: a screen that saves and then
        // reads back in the same request must not be handed the old answer.
        static::$memo[$key] = $value;
    }

    public static function enabled(string $key, ?bool $default = null): bool
    {
        return (bool) static::get($key, $default);
    }

    /**
     * Forget everything read so far.
     *
     * For the two places a process outlives a request: a queue worker between
     * jobs, and a test suite between tests.
     */
    public static function flush(): void
    {
        static::$memo = [];
    }
}
