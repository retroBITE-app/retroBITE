<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

$base      = dirname(__DIR__);
$storage   = $base . '/storage';
$public    = $base . '/public';
$database  = $base . '/database';
$resources = $base . '/resources';

return [
    'app_debug'       => (bool) Arr::get($_ENV, 'APP_DEBUG', true),
    'network'         => [
        'host_ip'  => Arr::get($_ENV, 'HOST_IP', '127.0.0.1'),
        'username' => Arr::get($_ENV, 'AUTH_USER', 'retrobite'),
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
    'games_path'      => Arr::get($_ENV, 'GAMES_PATH', $storage . '/games'),
    'migrations_path' => $database  . '/migrations',
    'db_path'         => Arr::get($_ENV, 'DB_PATH', $database . '/retrobite.db'),
];
