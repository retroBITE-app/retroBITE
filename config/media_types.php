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
     * On until somebody says otherwise: one cover with a fallback, one logo, one
     * backdrop and a screenshot. Deliberately not every type MediaKind lists —
     * those lists say which artwork may stand in for a role when several exist,
     * not what to fetch. Enabling all of them would pull four near-identical
     * logos per game, and a plain account downloads at 128 KB/s.
     */
    'default_enabled' => ['box-2D', 'box-3D', 'wheel', 'fanart', 'ss'],

];
