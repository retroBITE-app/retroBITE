<?php

declare(strict_types=1);

use App\Middleware\AuthMiddleware;
use App\Support\Routes;
use Slim\Routing\RouteCollectorProxy;

/*
 * Registration only — the routes themselves live in config/routes.php so PHP and
 * the frontend share one definition.
 */

foreach (Routes::requiringAuth(false) as $route) {
    $app->map([$route['method']], $route['path'], $route['handler']);
}

$app->group('', function (RouteCollectorProxy $group) {
    foreach (Routes::requiringAuth(true) as $route) {
        $group->map([$route['method']], $route['path'], $route['handler']);
    }
})->add(AuthMiddleware::class);
