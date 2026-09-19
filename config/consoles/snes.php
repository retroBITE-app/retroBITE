<?php

// screenscraper_id maps our console key to ScreenScraper's "systemeid" query
// parameter. Values from https://www.screenscraper.fr/api2/systemesListe.php —
// a console without an id here will return "Console not mapped to ScreenScraper"
// from the Identify endpoint.

return [
    'order' => 10,
    'name' => 'Super Nintendo',
    'brand' => 'Nintendo',
    'folder' => 'snes',
    'icon' => '/images/consoles/Nintendo - Super Nintendo Entertainment System.png',
    'file_icon' => '/images/consoles/Nintendo - Super Nintendo Entertainment System-content.png',
    'file_extensions' => ['smc', 'sfc', 'fig', 'swc', 'bs', 'gd3', 'gd7', 'dx2', 'bsx', 'zip', '7z'],
    'bios_extensions' => ['rom'],
    'exclude_files' => [],
    'screenscraper_id' => 4,
    'retroachievements_id' => 3,
    'cover_aspect' => '2/3',
    'cover_height' => 280,
];
