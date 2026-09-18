<?php

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
    | Login
    |--------------------------------------------------------------------------
    |
    | The sign-in page reports what the host holds — games catalogued, consoles
    | installed, disk used — under the form. That page is reachable without
    | credentials, so this exists to withhold the figures on a host that is not
    | only on a trusted LAN.
    |
    */

    'login_show_stats' => (bool) env('LOGIN_SHOW_STATS', true),

    /*
    |--------------------------------------------------------------------------
    | Library
    |--------------------------------------------------------------------------
    |
    | Root of the ROM library on disk. Each console gets a subfolder under it,
    | named by its `folder` key in config/consoles/.
    |
    | This is the same directory the `games` filesystem disk points at, and the
    | same one the share container exports as /games over SMB and FTP — so a
    | file dropped over the network is immediately visible to the app.
    |
    */

    'games_path' => storage_path('app/games'),

    /*
    |--------------------------------------------------------------------------
    | Docs
    |--------------------------------------------------------------------------
    |
    | Root of the markdown knowledge base. One folder per console under it, the
    | same keys as the library, plus a `.revisions` folder the UI never lists.
    |
    | This is the same directory the `docs` filesystem disk points at. Compose
    | bind-mounts it from the host (DOCS_PATH), because everything else under
    | storage/app lives in the container's writable layer and would not survive
    | `docker compose down`.
    |
    */

    'docs_path' => storage_path('app/docs'),
];
