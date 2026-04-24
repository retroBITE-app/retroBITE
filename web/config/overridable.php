<?php

declare(strict_types=1);

/**
 * Declares which config groups can be overridden at runtime via the `settings` table.
 *
 * Each top-level key names a config file (e.g. `consoles` → /config/consoles.php).
 * For group-level override, the whole item entry is replaced — one DB row per item.
 *
 * Field types supported in `schema`:
 *   - text    — scalar string
 *   - number  — integer (cast on save)
 *   - text[]  — list of strings
 */

return [
    'consoles' => [
        'label'     => 'Consoles',
        'item_name' => 'name',
        'schema'    => [
            'name'             => ['type' => 'text',   'label' => 'Display name',        'required' => true],
            'brand'            => ['type' => 'text',   'label' => 'Brand'],
            'icon'             => ['type' => 'text',   'label' => 'Icon URL'],
            'file_icon'        => ['type' => 'text',   'label' => 'File-icon URL'],
            'file_extensions'  => ['type' => 'text[]', 'label' => 'Game extensions',     'required' => true],
            'bios_extensions'  => ['type' => 'text[]', 'label' => 'BIOS extensions'],
            'exclude_files'    => ['type' => 'text[]', 'label' => 'Excluded files'],
            'screenscraper_id' => ['type' => 'number', 'label' => 'ScreenScraper id'],
        ],
    ],

    'regions' => [
        'label'     => 'Regions',
        'item_name' => 'name',
        'schema'    => [
            'name'  => ['type' => 'text',   'label' => 'Name',        'required' => true],
            'codes' => ['type' => 'text[]', 'label' => 'Match codes', 'required' => true],
            'icon'  => ['type' => 'text',   'label' => 'Icon URL'],
        ],
    ],
];
