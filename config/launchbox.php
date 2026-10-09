<?php

/*
 * The LaunchBox Games Database, where the retroBite score's players' ratings
 * come from (App\Services\LaunchBoxIndex).
 *
 * No account and no key: the whole database is one public zip, rebuilt every
 * day. Fallbacks are written `env(...) ?: default` for the reason given at the
 * top of config/retroachievements.php.
 */
return [
    'metadata_url' => env('LAUNCHBOX_METADATA_URL') ?: 'https://gamesdb.launchbox-app.com/Metadata.zip',

    // Generous: the zip is over a hundred megabytes, and a slow line is no
    // reason to give up on a download that runs once a week.
    'timeout' => (int) (env('LAUNCHBOX_TIMEOUT') ?: 900),
];
