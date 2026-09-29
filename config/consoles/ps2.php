<?php

return [
    'order' => 20,
    'name' => 'PlayStation 2',
    'brand' => 'Sony',
    'released' => 2000,
    'folder' => 'ps2',
    'icon' => '/images/consoles/Sony - PlayStation 2.png',
    'file_icon' => '/images/consoles/Sony - PlayStation 2-content.png',
    'file_extensions' => ['iso', 'bin', 'cue', 'img', 'mdf', 'nrg', 'zso', 'chd', 'cso'],
    'bios_extensions' => ['bin'],
    'toolbox_file_extensions' => ['iso', 'bin', 'img', 'mdf', 'nrg'],
    'exclude_files' => ['games.bin'],
    'screenscraper_id' => 58,
    'retroachievements_id' => 21,
    'cover_aspect' => '5/7',
    'layouts' => ['custom', 'opl', 'retroarch'],
    'default_layout' => 'custom',
    'converters' => ['chd-cd', 'chd-dvd', 'chd-to-cue', 'chd-to-iso', 'cso', 'zso', 'cso-to-iso'],
];
