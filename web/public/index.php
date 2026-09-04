<?php

declare(strict_types=1);

// Allow PHP built-in dev server to serve static files directly
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    if (is_file($file)) {
        return false;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/app/Helpers/config.php';
require dirname(__DIR__) . '/app/Helpers/logger.php';

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->run();
