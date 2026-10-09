<?php

return [
    'order' => 1240,
    'name' => 'Sony PlayStation',
    'brand' => 'Sony',
    'released' => 1994,
    'folder' => 'psx',
    'icon' => '/images/consoles/Sony - PlayStation.png',
    'file_icon' => '/images/consoles/Sony - PlayStation-content.png',
    'file_extensions' => ['cue', 'img', 'mdf', 'pbp', 'toc', 'cbn', 'm3u', 'ccd', 'chd', 'iso', 'bin', 'ecm', 'vcd'],
    'bios_extensions' => [],
    'toolbox_file_extensions' => [],
    'exclude_files' => [],
    'screenscraper_id' => 57,
    'retroachievements_id' => 12,
    'launchbox_platforms' => ['Sony Playstation'],
    'cover_aspect' => '1/1',
    'layouts' => ['custom', 'folders'],
    'default_layout' => 'custom',
    'converters' => ['chd-cd', 'chd-to-cue', 'ecm', 'unecm', 'vcd', 'vcd-to-cue'],
];
