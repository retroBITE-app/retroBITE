<?php

return [
    'order' => 50,
    'name' => 'Wii',
    'brand' => 'Nintendo',
    'released' => 2006,
    'folder' => 'wii',
    'icon' => '/images/consoles/Nintendo - Wii.png',
    'file_icon' => '/images/consoles/Nintendo - Wii-content.png',
    'file_extensions' => ['iso', 'wbfs', 'wad', 'dol', 'gcm', 'gcz', 'ciso', 'rvz', 'wia', 'm3u'],
    'bios_extensions' => ['bin'],
    'toolbox_file_extensions' => [],
    'exclude_files' => [],
    'screenscraper_id' => 16,
    'retroachievements_id' => 19,
    'launchbox_platforms' => ['Nintendo Wii'],
    'cover_aspect' => '5/7',
    'layouts' => ['custom'],
    'default_layout' => 'custom',
    'converters' => ['rvz', 'wbfs', 'nod-to-iso'],
];
