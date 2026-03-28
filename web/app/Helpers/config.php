<?php

declare(strict_types=1);

use \Illuminate\Support\Arr;

if (!function_exists('config'))
{
    /**
     * Access config values using dot notation.
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
