<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

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
     * Runtime edits to the shipped console config, keyed by console then by
     * config key. Absent means every console is as its file declares it.
     */
    public const CONSOLE_OVERRIDES = 'console_overrides';

    /**
     * The RetroAchievements web API key, stored encrypted.
     *
     * Here rather than in .env because it belongs in the settings screen, and
     * an operator who has to redeploy to change a key will not change it. Read
     * and written through getSecret()/putSecret(); reading it raw gives you
     * ciphertext.
     */
    public const RA_API_KEY = 'ra_api_key';

    /**
     * Which score the interface shows: hardcore, or softcore.
     *
     * A presentation choice and nothing more — hardcore is a mode in the
     * emulator, which retroBite cannot switch on. On by default, because it
     * is the figure RetroAchievements itself leads with. The game page shows
     * both whatever this says.
     */
    public const RA_HARDCORE_PRIMARY = 'ra_hardcore_primary';

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
        self::RA_HARDCORE_PRIMARY => true,
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
     * Read a value that was stored encrypted.
     *
     * Returns null rather than throwing when the ciphertext will not open,
     * which happens for real: rotating APP_KEY leaves every secret in here
     * unreadable, and a settings page that fatals is worse than one that shows
     * an empty field to type into again.
     */
    public static function getSecret(string $key, ?string $default = null): ?string
    {
        $stored = static::get($key);

        if (! is_string($stored) || $stored === '') {
            return $default;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            return $default;
        }
    }

    /** Store a value encrypted, so it is not sitting in plain text in a dump. */
    public static function putSecret(string $key, ?string $value): void
    {
        static::put($key, ($value === null || $value === '') ? null : Crypt::encryptString($value));
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
