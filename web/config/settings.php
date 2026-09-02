<?php

declare(strict_types=1);

use App\Support\Obfuscated;
use Illuminate\Support\Arr;

$base      = dirname(__DIR__);
$storage   = $base . '/storage';
$public    = $base . '/public';
$database  = $base . '/database';
$resources = $base . '/resources';

return [
    'app_debug'       => (bool) Arr::get($_ENV, 'APP_DEBUG', true),

    // Vite HMR dev server — override the port when 5173 is taken by another project
    'vite_dev_url'    => Arr::get(
        $_ENV,
        'VITE_DEV_URL',
        'http://localhost:' . Arr::get($_ENV, 'VITE_PORT', '5173')
    ),
    'network'         => [
        'host_ip'  => Arr::get($_ENV, 'HOST_IP', '127.0.0.1'),
        'username' => Arr::get($_ENV, 'AUTH_USER', 'retrobite'),
    ],

    'screenscraper'   => [
        'dev_id'       => Obfuscated::reveal('AAQfHw4='),
        'dev_password' => Obfuscated::reveal('ICkQAFwOCCY9KDw='),
        'user'         => Arr::get($_ENV, 'SCREENSCRAPER_USER', ''),
        'password'     => Arr::get($_ENV, 'SCREENSCRAPER_PASSWORD', ''),
        'endpoint'     => 'https://api.screenscraper.fr/api2',
    ],

    // Root paths
    'root_path'       => $base,
    'storage_path'    => $storage,
    'public_path'     => $public,
    'database_path'   => $database,
    'resources_path'  => $resources,

    // Extensible paths
    'views_path'      => $resources . '/views',
    'build_path'      => $public    . '/build',
    'image_path'      => $public    . '/images',
    'tmp_path'        => $storage   . '/tmp',

    // Locally cached provider images (served by nginx from public/)
    'storage'         => [
        'metadata_path' => $public . '/storage/metadata',
        'metadata_url'  => '/storage/metadata',
    ],
    'games_path'      => Arr::get($_ENV, 'GAMES_PATH', $storage . '/games'),
    'migrations_path' => $database  . '/migrations',
    'db_path'         => Arr::get($_ENV, 'DB_PATH', $database . '/retrobite.db'),
];
