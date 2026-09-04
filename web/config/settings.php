<?php

declare(strict_types=1);

use App\Support\Env;
use App\Support\Obfuscated;

$base      = dirname(__DIR__);
$storage   = $base . '/storage';
$public    = $base . '/public';
$database  = $base . '/database';
$resources = $base . '/resources';

return [
    'app_debug'       => (bool) Env::get('APP_DEBUG', false),

    // Cap on a whole assembled upload. nginx's client_max_body_size only caps one chunk.
    'upload_max_bytes' => (int) Env::get('UPLOAD_MAX_BYTES', 64 * 1024 * 1024 * 1024),

    // The login page is public, so its stats block discloses library size, console
    // count and disk capacity to anyone who can reach the host. Set false to hide it.
    'login_show_stats' => (bool) Env::get('LOGIN_SHOW_STATS', true),

    // Vite HMR dev server — override the port when 5173 is taken by another project
    'vite_dev_url'    => Env::get(
        'VITE_DEV_URL',
        'http://localhost:' . Env::get('VITE_PORT', '5173')
    ),
    'network'         => [
        'host_ip'  => Env::get('HOST_IP', '127.0.0.1'),
        'username' => Env::get('AUTH_USER', 'retrobite'),
    ],

    'screenscraper'   => [
        'dev_id'       => Obfuscated::reveal('AAQfHw4='),
        'dev_password' => Obfuscated::reveal('ICkQAFwOCCY9KDw='),
        'user'         => Env::get('SCREENSCRAPER_USER', ''),
        'password'     => Env::get('SCREENSCRAPER_PASSWORD', ''),
        'endpoint'     => 'https://api.screenscraper.fr/api2',

        // The provider is regularly slow, and a timeout mid-identification loses
        // the whole lookup. Raise these when it is having a bad day.
        'connect_timeout' => (int) Env::get('SCREENSCRAPER_CONNECT_TIMEOUT', 15),
        'timeout'         => (int) Env::get('SCREENSCRAPER_TIMEOUT', 45),
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
    'games_path'      => Env::get('GAMES_PATH', $storage . '/games'),
    'migrations_path' => $database  . '/migrations',
    'db_path'         => Env::get('DB_PATH', $database . '/retrobite.db'),
];
