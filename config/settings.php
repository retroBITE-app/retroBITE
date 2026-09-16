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
    | Interface
    |--------------------------------------------------------------------------
    |
    | The CRT scanline overlay drawn across key art and the login backdrop.
    | Part of the look rather than decoration you can ignore — set false for a
    | flat presentation.
    |
    */

    'interface' => [
        'scanlines' => (bool) env('UI_SCANLINES', true),
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
];
