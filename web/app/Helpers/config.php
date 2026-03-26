<?php

declare(strict_types=1);

use \Illuminate\Support\Arr;

if (!function_exists('config')) {
    /**
     * Access config values using dot notation.
     *
     * The first segment maps to a file in config/.
     * Remaining segments traverse the returned array.
     *
     * Examples:
     *   config('settings.db_path')
     *   config('settings.games_path', '/games')
     *   config('settings')            // returns the full settings array
     */
    function config(string $key, mixed $default = null): mixed
    {
        static $cache = [];

        $segments = explode('.', $key);
        $file     = array_shift($segments);

        if (!array_key_exists($file, $cache)) {
            $path        = dirname(__DIR__, 2) . "/config/{$file}.php";
            $cache[$file] = file_exists($path) ? require $path : [];
        }

        if (empty($segments)) {
            return $cache[$file];
        }

        return Arr::get($cache[$file], implode('.', $segments), $default);
    }
}
