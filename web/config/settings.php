<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

$base = dirname(__DIR__);

return [
    'db_path'         => Arr::get($_ENV, 'DB_PATH', $base . '/database/retrobite.db'),
    'games_path'      => $base . '/storage/games',
    'app_debug'       => (bool) Arr::get($_ENV, 'APP_DEBUG', true),

    // Internal paths
    'root_path'       => $base,
    'migrations_path' => $base . '/database/migrations',
    'views_path'      => $base . '/resources/views',
    'image_path'      => $base . '/public/images',
];
