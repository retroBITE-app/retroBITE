<?php

declare(strict_types=1);

use App\Support\Obfuscated;

return [

    /*
    |--------------------------------------------------------------------------
    | Network
    |--------------------------------------------------------------------------
    |
    | Where the share container is reachable. NetworkService opens a TCP
    | connection to each protocol's port against this host to report whether
    | SMB and FTP are actually up.
    |
    | HOST_IP is the machine's LAN address — the same value vsftpd advertises
    | for passive mode, so "localhost" is wrong here whenever a console is
    | expected to connect.
    |
    */

    'network' => [
        'host_ip' => env('HOST_IP', '127.0.0.1'),
        'username' => env('AUTH_USER', 'retrobite'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Library
    |--------------------------------------------------------------------------
    |
    | Root of the ROM library on disk. Each console gets a subfolder under it,
    | named by its `folder` key in config/consoles.php.
    |
    | This is the same directory the `games` filesystem disk points at, and the
    | same one the share container exports as /games over SMB and FTP — so a
    | file dropped over the network is immediately visible to the app.
    |
    */

    'games_path' => storage_path('app/games'),

    /*
    |--------------------------------------------------------------------------
    | ScreenScraper
    |--------------------------------------------------------------------------
    |
    | Metadata and artwork provider — https://www.screenscraper.fr
    |
    | The dev credentials identify retroBITE itself to the provider and ship
    | with the source, so they are concealed rather than committed in plain
    | text; see App\Support\Obfuscated for what that does and does not buy you.
    |
    | The user credentials are per-installation and belong in the environment.
    | Without them the API still answers, but on the much smaller anonymous
    | quota.
    |
    */

    'screenscraper' => [
        'dev_id' => Obfuscated::reveal('AAQfHw4='),
        'dev_password' => Obfuscated::reveal('ICkQAFwOCCY9KDw='),
        'user' => env('SCREENSCRAPER_USER', ''),
        'password' => env('SCREENSCRAPER_PASSWORD', ''),
        'endpoint' => env('SCREENSCRAPER_ENDPOINT', 'https://api.screenscraper.fr/api2'),

        // The provider is regularly slow, and a timeout mid-identification
        // loses the whole lookup. Raise these when it is having a bad day.
        'connect_timeout' => (int) env('SCREENSCRAPER_CONNECT_TIMEOUT', 15),
        'timeout' => (int) env('SCREENSCRAPER_TIMEOUT', 45),
    ],

];
