<?php

declare(strict_types=1);

return [
    'ps2' => [
        'name'            => 'PlayStation 2',
        'brand'           => 'Sony',
        'folder'          => 'ps2',
        'icon'            => '/images/consoles/Sony - PlayStation 2.png',
        'file_icon'       => '/images/consoles/Sony - PlayStation 2-content.png',
        'file_extensions' => ['iso', 'bin', 'img', 'mdf', 'nrg'],
        'bios_extensions' => ['bin'],
        'exclude_files'   => ['games.bin'],
    ],
    'ps3' => [
        'name'            => 'PlayStation 3',
        'brand'           => 'Sony',
        'folder'          => 'ps3',
        'icon'            => '/images/consoles/Sony - PlayStation 3.png',
        'file_icon'       => '/images/consoles/Sony - PlayStation 3-content.png',
        'file_extensions' => ['pkg', 'iso', 'ps3'],
        'bios_extensions' => ['bin', 'pup'],
    ],
    'gc' => [
        'name'            => 'GameCube',
        'brand'           => 'Nintendo',
        'folder'          => 'gc',
        'icon'            => '/images/consoles/Nintendo - GameCube.png',
        'file_icon'       => '/images/consoles/Nintendo - GameCube-content.png',
        'file_extensions' => ['iso', 'gcm', 'ciso', 'gcz', 'nkit.iso'],
        'bios_extensions' => ['bin'],
    ],
    'wii' => [
        'name'            => 'Wii',
        'brand'           => 'Nintendo',
        'folder'          => 'wii',
        'icon'            => '/images/consoles/Nintendo - Wii.png',
        'file_icon'       => '/images/consoles/Nintendo - Wii-content.png',
        'file_extensions' => ['iso', 'wbfs', 'wad', 'dol'],
        'bios_extensions' => ['bin'],
    ],
    'xbox' => [
        'name'            => 'Xbox',
        'brand'           => 'Microsoft',
        'folder'          => 'xbox',
        'icon'            => '/images/consoles/Microsoft - Xbox.png',
        'file_icon'       => '/images/consoles/Microsoft - Xbox-content.png',
        'file_extensions' => ['iso', 'xbe'],
        'bios_extensions' => ['bin', 'rom'],
    ],
    'dreamcast' => [
        'name'            => 'Dreamcast',
        'brand'           => 'Sega',
        'folder'          => 'dreamcast',
        'icon'            => '/images/consoles/Sega - Dreamcast.png',
        'file_icon'       => '/images/consoles/Sega - Dreamcast-content.png',
        'file_extensions' => ['cdi', 'gdi', 'chd', 'iso'],
        'bios_extensions' => ['bin'],
    ],
];
