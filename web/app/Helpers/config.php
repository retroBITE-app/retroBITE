<?php

declare(strict_types=1);

use App\Support\SettingsOverrides;
use Illuminate\Support\Arr;

if (!function_exists('config'))
{
    /**
     * Access config values using dot notation. Merges DB overrides
     * (see App\Support\SettingsOverrides) for groups listed in config/overridable.php.
     *
     * The raw file is cached for the life of the process; overrides are re-applied
     * on every call so SettingsOverrides::invalidate() takes effect immediately.
     */
    function config(string $key, mixed $default = null): mixed
    {
        static $raw = [];

        $segments = explode('.', $key);
        $file     = array_shift($segments);

        if (!array_key_exists($file, $raw)) {
            $path       = dirname(__DIR__, 2) . "/config/{$file}.php";
            $raw[$file] = file_exists($path) ? require $path : [];
        }

        $value = SettingsOverrides::applyTo($file, $raw[$file]);

        if (empty($segments)) {
            return $value;
        }

        return Arr::get($value, implode('.', $segments), $default);
    }
}
