<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Per-process memoized loader for DB config overrides.
 *
 * Called from the `config()` helper (see app/Helpers/config.php). Only the
 * groups listed in `config/overridable.php` can trigger a DB lookup, which
 * keeps early-boot `config('settings.*')` calls safe (they happen before
 * Eloquent is initialised).
 */
final class SettingsOverrides
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $loaded = null;

    /** Bumped on every invalidation so config() knows its merge is stale. */
    private static int $generation = 0;

    /**
     * Merge overrides into a freshly-loaded config file's array, per item rather than
     * per group — a saved override holds only schema fields, so replacing a whole
     * entry would drop the unexposed ones (for consoles, `folder`).
     */
    public static function applyTo(string $file, array $value): array
    {
        if ($file === 'overridable') {
            return $value; // avoid recursion when loading registry itself
        }

        if (!in_array($file, Registry::groups(), true)) {
            return $value;
        }

        foreach (self::forGroup($file) as $key => $override) {
            if (!is_array($override)) {
                continue;
            }

            $existing = Arr::get($value, $key);

            $value[$key] = is_array($existing)
                ? array_replace($existing, $override)
                : $override;
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public static function forGroup(string $group): array
    {
        if (self::$loaded === null) {
            self::loadAll();
        }

        return self::$loaded[$group] ?? [];
    }

    /**
     * Drop the memoized overrides so the next read hits the database again.
     */
    public static function invalidate(): void
    {
        self::$loaded = null;
        self::$generation++;
    }

    /**
     * Current override generation. config() caches its merged view against this.
     */
    public static function generation(): int
    {
        return self::$generation;
    }

    /**
     * Load every override row once per process, grouped by config file then item key.
     * Failure is logged, not thrown: it is expected before the table exists.
     */
    private static function loadAll(): void
    {
        self::$loaded = [];

        try {
            foreach (Setting::all() as $row) {
                self::$loaded[$row->group][$row->key] = json_decode((string) $row->value, true);
            }
        } catch (Throwable $e) {
            // Any fault here silently reverts every override to its file default.
            logger()->warning('Could not load settings overrides; using file defaults', [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);
        }
    }
}
