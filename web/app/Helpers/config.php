<?php

declare(strict_types=1);

use App\Support\SettingsOverrides;
use Illuminate\Support\Arr;

if (!function_exists('config')) {
    /**
     * Access config values using dot notation, with DB overrides merged in for the
     * groups listed in config/overridable.php.
     *
     * Both the raw file and the merged result are cached for the life of the
     * process; the merge is discarded whenever SettingsOverrides::invalidate()
     * bumps its generation. Re-merging on every call cost hundreds of array
     * rebuilds per page render.
     */
    function config(string $key, mixed $default = null): mixed
    {
        static $raw        = [];
        static $merged     = [];
        static $generation = -1;

        if ($generation !== SettingsOverrides::generation()) {
            $merged     = [];
            $generation = SettingsOverrides::generation();
        }

        $segments = explode('.', $key);
        $file     = array_shift($segments);

        if (!array_key_exists($file, $raw)) {
            $path       = dirname(__DIR__, 2) . "/config/{$file}.php";
            $raw[$file] = file_exists($path) ? require $path : [];
        }

        if (!array_key_exists($file, $merged)) {
            $merged[$file] = SettingsOverrides::applyTo($file, $raw[$file]);
        }

        if ($segments === []) {
            return $merged[$file];
        }

        return Arr::get($merged[$file], implode('.', $segments), $default);
    }
}
