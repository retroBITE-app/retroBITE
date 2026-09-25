<?php

// The provider media types worth offering, grouped as the settings screen draws
// them. Hardcoded rather than read from mediasJeuListe.php: that endpoint needs
// credentials and a cache, and this is a settings screen whose options change
// about once a year. A type the provider adds is a line here, not a migration —
// which is why this is config and not an enum.
//
// The groups are a list rather than a map, so the order is the file's order and
// each label sits beside the types it heads.

return [

    'groups' => [
        [
            'label' => 'Boxes and physical media',
            'types' => ['box-2D', 'box-3D', 'box-2D-back', 'box-2D-side', 'box-texture', 'support-2D', 'support-texture'],
        ],
        [
            'label' => 'Screens',
            'types' => ['ss', 'sstitle', 'mixrbv1', 'mixrbv2'],
        ],
        [
            'label' => 'Logos and marquees',
            'types' => ['wheel', 'wheel-hd', 'wheel-carbon', 'wheel-steel', 'screenmarquee', 'screenmarqueesmall', 'steamgrid'],
        ],
        [
            'label' => 'Backgrounds',
            'types' => ['fanart', 'bezel-16-9'],
        ],
        [
            'label' => 'Heavy extras',
            'types' => ['video', 'video-normalized', 'manuel'],
        ],
    ],

    /*
     * What each type is, in words, for the settings screen: the provider's own
     * names are terse ("box-2D-back", "mixrbv1") and several only make sense to
     * somebody who already knows. A type missing here shows its name alone.
     */
    'labels' => [
        'box-2D' => 'Box front',
        'box-3D' => 'Box, 3D render',
        'box-2D-back' => 'Box back',
        'box-2D-side' => 'Box spine',
        'box-texture' => 'Box wrap, unfolded',
        'support-2D' => 'Disc or cartridge',
        'support-texture' => 'Disc or cartridge label, flat',
        'ss' => 'In-game screenshot',
        'sstitle' => 'Title screen',
        'mixrbv1' => 'Composite: screenshot, box and logo',
        'mixrbv2' => 'Composite, second layout',
        'wheel' => 'Logo',
        'wheel-hd' => 'Logo, high resolution',
        'wheel-carbon' => 'Logo on carbon',
        'wheel-steel' => 'Logo on steel',
        'screenmarquee' => 'Arcade marquee',
        'screenmarqueesmall' => 'Arcade marquee, small',
        'steamgrid' => 'Steam grid banner',
        'fanart' => 'Fan art wallpaper',
        'bezel-16-9' => 'Screen bezel, 16:9',
        'video' => 'Gameplay video',
        'video-normalized' => 'Gameplay video, normalised',
        'manuel' => 'Manual',
    ],

    /*
     * On until somebody says otherwise: one cover with a fallback, one logo, one
     * backdrop, a screenshot, and the disc — what OPL draws as a game's icon
     * (ART/<serial>_ICO), small enough to cost little. Deliberately not every type MediaKind lists —
     * those lists say which artwork may stand in for a role when several exist,
     * not what to fetch. Enabling all of them would pull four near-identical
     * logos per game, and a plain account downloads at 128 KB/s.
     */
    'default_enabled' => ['box-2D', 'box-3D', 'support-2D', 'wheel', 'fanart', 'ss'],

];
