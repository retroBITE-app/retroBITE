<?php

declare(strict_types=1);

/**
 * Identity, links and credits behind the settings page's About tab. The version
 * and repo url are shared with every page, so this is the one place they live.
 */

$repo = 'https://github.com/mattiasghodsian/retroBite';

return [
    'name'     => 'retroBITE',
    'version'  => 'v0.0.1',
    'repo_url' => $repo,

    // Rendered in order as the About tab's opening paragraphs.
    'summary'  => [
        'A self-hosted collection manager for retro game files. It scans a games directory, identifies what it finds, and keeps the metadata and artwork attached to each file.',
        'Files stay where they are. Consoles reach the library over SMB or FTP, so an original console with a network adapter loads from the same folder a desktop emulator does.',
        'It is not an emulator and it does not play games — that happens on the hardware or the emulator of your choice.',
    ],

    // `icon` names map to a Phosphor component in SettingsAbout.vue.
    'links'    => [
        ['icon' => 'github', 'label' => 'Source on GitHub',      'url' => $repo],
        ['icon' => 'bug',    'label' => 'Report an issue',        'url' => $repo . '/issues'],
        ['icon' => 'heart',  'label' => 'Support development',    'url' => $repo . '/sponsors'],
    ],

    'credits'  => [
        ['icon' => 'images',   'name' => 'ScreenScraper.fr', 'role' => 'Game metadata, box art, screenshots and title screens'],
        ['icon' => 'gamepad',  'name' => 'Libretro',         'role' => 'Console iconography — retroarch-assets/xmb/retrosystem/png'],
        ['icon' => 'drives',   'name' => 'Samba & vsftpd',   'role' => 'The SMB and FTP shares consoles load from'],
        ['icon' => 'code',     'name' => 'Slim',             'role' => 'PSR-7 routing and middleware'],
        ['icon' => 'database', 'name' => 'Illuminate',       'role' => "Eloquent and Laravel's support helpers"],
        ['icon' => 'code',     'name' => 'Vue & Inertia',    'role' => 'The interface, and the bridge that carries PHP state into it'],
        ['icon' => 'brush',    'name' => 'Tailwind CSS',     'role' => 'The tokens and utilities behind every screen'],
        ['icon' => 'shapes',   'name' => 'Phosphor Icons',   'role' => 'The icon set'],
        ['icon' => 'type',     'name' => 'Open Sans',        'role' => 'Typeface, served by the app itself'],
    ],
];
