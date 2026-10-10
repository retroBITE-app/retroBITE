<?php

declare(strict_types=1);

/*
 * Identity, links and credits behind Settings → About. The version is
 * config('app.version'), written by CI; everything else lives here.
 */

$repo = 'https://github.com/retroBITE-app/retroBITE';

return [
    'repo_url' => $repo,

    // Drawn in the Version card, under the build rows.
    'website' => ['icon' => 'globe-alt', 'label' => 'Website', 'url' => 'https://retrobite.app'],

    // `icon` is a Flux icon name: Heroicons, or an override in resources/views/flux/icon.
    'links' => [
        ['icon' => 'folder-git-2', 'label' => 'Source on GitHub', 'url' => $repo],
        ['icon' => 'cube', 'label' => 'Images on Docker Hub', 'url' => 'https://hub.docker.com/u/retrobite'],
        ['icon' => 'tag', 'label' => 'Releases', 'url' => $repo.'/releases'],
        ['icon' => 'bug-ant', 'label' => 'Report an issue', 'url' => $repo.'/issues'],
    ],

    // Mirrors BUILT ON at retrobite.app; keep the two in step.
    'credits' => [
        ['icon' => 'photo', 'name' => 'ScreenScraper.fr', 'role' => 'Game identification, metadata and artwork'],
        ['icon' => 'trophy', 'name' => 'RetroAchievements', 'role' => 'Achievement sets, unlocks, the hash index and player counts for the score'],
        ['icon' => 'star', 'name' => 'LaunchBox Games Database', 'role' => 'Players\' ratings behind the retroBite score'],
        ['icon' => 'puzzle-piece', 'name' => 'Libretro', 'role' => 'Console iconography from retroarch-assets'],
        ['icon' => 'server-stack', 'name' => 'Samba & vsftpd', 'role' => 'The SMB and FTP shares consoles load from'],
        ['icon' => 'share', 'name' => 'ps3netsrv', 'role' => 'Streams PS3, PS2 and PS1 games to webMAN MOD'],
        ['icon' => 'circle-stack', 'name' => 'MariaDB', 'role' => 'The library database'],
        ['icon' => 'bolt', 'name' => 'Laravel & Livewire', 'role' => 'The app and its interface'],
        ['icon' => 'signal', 'name' => 'Laravel Reverb', 'role' => 'Live updates over WebSockets'],
        ['icon' => 'paint-brush', 'name' => 'Tailwind CSS', 'role' => 'Styling for the whole interface'],
        ['icon' => 'cube', 'name' => 'Docker', 'role' => 'The image and the Compose stack it runs in'],
        ['icon' => 'square-3-stack-3d', 'name' => 'chdman', 'role' => 'CUE/BIN, IMG and ISO to CHD, and back'],
        ['icon' => 'archive-box', 'name' => 'maxcso', 'role' => 'ISO to CSO and ZSO, and back'],
        ['icon' => 'arrows-pointing-in', 'name' => 'ecm & unecm', 'role' => 'Disc images to ECM, and ECM back to BIN'],
        ['icon' => 'arrows-right-left', 'name' => 'cue2pops & pops2cue', 'role' => 'CUE to POPStarter VCD, and VCD back to BIN/CUE'],
        ['icon' => 'arrow-path', 'name' => 'nodtool', 'role' => 'GameCube and Wii images to RVZ, WBFS and ISO'],
        ['icon' => 'cube-transparent', 'name' => 'extract-xiso', 'role' => 'Xbox ISO tools, bundled for upcoming support'],
        ['icon' => 'lock-open', 'name' => 'ps3dec', 'role' => 'Redump PS3 images decrypted with their disc key'],
    ],
];
