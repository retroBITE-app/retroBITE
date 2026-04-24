<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;
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

    /**
     * Merge overrides into a freshly-loaded config file's array.
     */
    public static function applyTo(string $file, array $value): array
    {
        if ($file === 'overridable') {
            return $value; // avoid recursion when loading registry itself
        }

        if (!in_array($file, Registry::groups(), true)) {
            return $value;
        }

        $overrides = self::forGroup($file);

        return $overrides === [] ? $value : array_replace($value, $overrides);
    }

    /** @return array<string, mixed> */
    public static function forGroup(string $group): array
    {
        if (self::$loaded === null) {
            self::loadAll();
        }

        return self::$loaded[$group] ?? [];
    }

    public static function invalidate(): void
    {
        self::$loaded = null;
    }

    private static function loadAll(): void
    {
        self::$loaded = [];

        try {
            foreach (Setting::all() as $row) {
                $decoded = json_decode((string) $row->value, true);
                self::$loaded[$row->group][$row->key] = $decoded;
            }
        } catch (Throwable) {
            // settings table missing (pre-migration) or Eloquent not yet booted — silent.
        }
    }
}
