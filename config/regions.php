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

];
