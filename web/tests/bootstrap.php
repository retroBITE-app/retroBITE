<?php

declare(strict_types=1);

/**
 * Test bootstrap. Points the app's paths at a scratch directory before anything
 * reads config, so a test run never touches the developer's real database or
 * game library.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$scratch = sys_get_temp_dir() . '/retrobite-tests';

if (!is_dir($scratch)) {
    mkdir($scratch, 0777, true);
}

$_ENV['DB_PATH']    = $scratch . '/retrobite.db';
$_ENV['GAMES_PATH'] = $scratch . '/games';

if (!is_dir($_ENV['GAMES_PATH'])) {
    mkdir($_ENV['GAMES_PATH'], 0777, true);
}
