<?php

// ScreenScraper's region shortnames, as its media entries carry them. The list
// is the ones a library is realistically full of, not every code the provider
// knows — anything missing still works, it just has no label in the settings.
//
// "ss" is the provider's own neutral entry rather than a place, which is why it
// leads the fallback below.

return [

    'labels' => [
        'ss' => 'ScreenScraper default',
        'wor' => 'World',
        'eu' => 'Europe',
        'us' => 'United States',
        'jp' => 'Japan',
        'uk' => 'United Kingdom',
        'fr' => 'France',
        'de' => 'Germany',
        'sp' => 'Spain',
        'it' => 'Italy',
        'se' => 'Sweden',
        'no' => 'Norway',
        'dk' => 'Denmark',
        'fi' => 'Finland',
        'nl' => 'Netherlands',
        'au' => 'Australia',
        'br' => 'Brazil',
        'kr' => 'Korea',
        'cn' => 'China',
        'asi' => 'Asia',
        'ame' => 'Americas',
        'oce' => 'Oceania',
    ],

    /*
     * Tried in order when the preferred region has nothing, before giving up
     * and taking whatever the provider offers. Neutral entries first: a World
     * cover is a better stand-in for a missing European one than a Japanese
     * cover is.
     */
    'fallback' => ['ss', 'wor', 'eu', 'us', 'jp'],

    /*
     * The five flags shipped in public/images/regions. Only codes those
     * pictures actually depict are mapped — a code with no icon falls back to
     * a chip carrying its label, which is better than a flag that is wrong.
     * Adding a flag is an edit here, not in code.
     */
    'icons' => [
        // The provider's own neutral entry is a world release in practice.
        'ss' => '/images/regions/world.png',
        'wor' => '/images/regions/world.png',
        'eu' => '/images/regions/eu.png',
        'us' => '/images/regions/usa.png',
        'jp' => '/images/regions/japan.png',
        'se' => '/images/regions/scandinavia.png',
        'no' => '/images/regions/scandinavia.png',
        'dk' => '/images/regions/scandinavia.png',
        'fi' => '/images/regions/scandinavia.png',
    ],

];
