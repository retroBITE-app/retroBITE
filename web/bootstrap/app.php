<?php

declare(strict_types=1);

use App\Database\Schema;
use Slim\Factory\AppFactory;

$container = require __DIR__ . '/container.php';
AppFactory::setContainer($container);
$app = AppFactory::create();

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->addErrorMiddleware((bool) ($_ENV['APP_DEBUG'] ?? true), true, true);

// Run DB migrations on every startup (safe — only applies pending ones)
Schema::migrate();

// Boot Eloquent Capsule (must come after migrate so the DB file exists)
require __DIR__ . '/database.php';

require dirname(__DIR__) . '/routes/web.php';

return $app;
