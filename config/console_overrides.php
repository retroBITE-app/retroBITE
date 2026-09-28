<?php

declare(strict_types=1);

/**
 * Which keys of a config/consoles/ entry somebody may change at runtime.
 *
 * The console files are shipped config, redeployed rather than edited, and a
 * wrong ScreenScraper id or a missing file extension is otherwise unfixable
 * without rebuilding the image. An override edits an existing console; it can
 * never invent one, and it can never move a console's files — `folder` is
 * absent on purpose, because ConsoleSourceFolder owns where a console reads
 * from and the previous build collapsed a console's root onto the shared games
 * directory by exposing it here.
 *
 * Field types:
 *   text   — scalar string
 *   url    — scalar string, http(s) or a root-relative path
 *   number — integer, or null when the field is emptied
 *   text[] — comma-separated list, kept as typed
 *   ext[]  — comma-separated list, lowercased; extension matching is case-folded
 *
 * A field left empty means "whatever the console file says", which is also what
 * a field equal to its shipped value means. Neither is stored.
 */

return [
    'name' => [
        'type' => 'text',
        'label' => 'Display name',
        'required' => true,
        'description' => 'Shown in the sidebar and the library.',
    ],

    'brand' => [
        'type' => 'text',
        'label' => 'Brand',
        'description' => 'Searched alongside the name.',
    ],

    'icon' => [
        'type' => 'url',
        'label' => 'Icon',
        'description' => 'Root-relative path or a full URL.',
    ],

    'file_icon' => [
        'type' => 'url',
        'label' => 'File icon',
        'description' => 'Shown against files, not the console.',
    ],

    'file_extensions' => [
        'type' => 'ext[]',
        'label' => 'Game extensions',
        'required' => true,
        'description' => 'Only these are picked up as games (Comma separated).',
    ],

    'bios_extensions' => [
        'type' => 'ext[]',
        'label' => 'BIOS extensions',
        'description' => 'Filed as BIOS rather than as games (Comma separated).',
    ],

    'exclude_files' => [
        'type' => 'text[]',
        'label' => 'Excluded files',
        'description' => 'Filenames the scanner skips outright (Comma separated).',
    ],

    'screenscraper_id' => [
        'type' => 'number',
        'label' => 'ScreenScraper id',
        'description' => 'The provider\'s systemeid. Wrong here and nothing matches.',
    ],

    'retroachievements_id' => [
        'type' => 'number',
        'label' => 'RetroAchievements id',
        'description' => 'RetroAchievements\' ConsoleID, also RAHasher\'s systemid. Not the ScreenScraper one.',
    ],

    'cover_aspect' => [
        'type' => 'text',
        'label' => 'Cover aspect',
        'description' => 'CSS ratio for key art, e.g. 2/3. The shape most of the console\'s boxes have: it also decides how many to a shelf row.',
    ],

];
