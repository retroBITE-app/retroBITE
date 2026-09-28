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
     * What each type is, for the settings screen: the provider's names are
     * terse (mixrbv2) and sometimes French (manuel). A type missing here is
     * shown by its raw name alone.
     */
    'types' => [
        'box-2D' => ['name' => 'Box art', 'description' => 'The front of the box, flat, as scanned.'],
        'box-3D' => ['name' => 'Box art, 3D', 'description' => 'The box rendered in perspective, spine and all.'],
        'box-2D-back' => ['name' => 'Box back', 'description' => 'The back of the box, flat.'],
        'box-2D-side' => ['name' => 'Box spine', 'description' => 'The side of the box, for shelves that show spines.'],
        'box-texture' => ['name' => 'Box texture', 'description' => 'The whole box unfolded — front, spine and back in one image — for front-ends that build their own 3D box.'],
        'support-2D' => ['name' => 'Cartridge or disc', 'description' => 'The medium itself: the cartridge, disc or tape, photographed or scanned.'],
        'support-texture' => ['name' => 'Cartridge or disc label', 'description' => 'The medium\'s label, flat, for front-ends that build their own 3D cartridge.'],
        'ss' => ['name' => 'Screenshot', 'description' => 'A frame from gameplay.'],
        'sstitle' => ['name' => 'Title screen', 'description' => 'The game\'s title screen.'],
        'mixrbv1' => ['name' => 'Mix, layout 1', 'description' => 'Screenshot, box, cartridge and logo composed into one picture, in Recalbox\'s first layout.'],
        'mixrbv2' => ['name' => 'Mix, layout 2', 'description' => 'The same composition in Recalbox\'s second layout, which most front-ends use.'],
        'wheel' => ['name' => 'Logo', 'description' => 'The game\'s logo on a transparent background, as front-ends show it in a wheel or carousel.'],
        'wheel-hd' => ['name' => 'Logo, HD', 'description' => 'The same logo at a higher resolution.'],
        'wheel-carbon' => ['name' => 'Logo on carbon', 'description' => 'The logo on a carbon-fibre plate.'],
        'wheel-steel' => ['name' => 'Logo on steel', 'description' => 'The logo on a brushed-steel plate.'],
        'screenmarquee' => ['name' => 'Marquee', 'description' => 'A wide banner the shape of an arcade cabinet\'s marquee, logo on artwork.'],
        'screenmarqueesmall' => ['name' => 'Marquee, small', 'description' => 'A smaller cut of the marquee banner.'],
        'steamgrid' => ['name' => 'Steam grid', 'description' => 'A wide banner cut for Steam\'s library grid.'],
        'fanart' => ['name' => 'Fan art', 'description' => 'Wallpaper-style artwork, shown behind the game as a backdrop.'],
        'bezel-16-9' => ['name' => 'Bezel, 16:9', 'description' => 'A frame drawn around a 4:3 game to fill a widescreen TV.'],
        'video' => ['name' => 'Video', 'description' => 'A short clip of gameplay. Several megabytes per game.'],
        'video-normalized' => ['name' => 'Video, normalised', 'description' => 'The same clip re-encoded to one size and format, which players cope with better.'],
        'manuel' => ['name' => 'Manual', 'description' => 'The instruction manual, as a PDF — manuel is the provider\'s French. Often tens of megabytes.'],
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
