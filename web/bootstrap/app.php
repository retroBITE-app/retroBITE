<?php

declare(strict_types=1);

use App\Http\ErrorRenderer;
use App\Middleware\CsrfMiddleware;
use App\Middleware\SessionMiddleware;
use Monolog\ErrorHandler;
use Slim\Factory\AppFactory;

$container = require __DIR__ . '/container.php';
AppFactory::setContainer($container);
$app = AppFactory::create();

// Capture all PHP errors, warnings, and fatal errors into the log file
ErrorHandler::register(logger());

// Boot Eloquent. Migrations are deliberately NOT run here — `php console migrate`
// owns them, invoked from the container entrypoint, so a request never pays for a
// glob plus two queries and concurrent workers cannot race on a fresh volume.
require __DIR__ . '/database.php';

/*
 * Middleware. Slim's stack is LIFO — the last one added runs outermost — so this
 * list is written in reverse execution order. Effective order is:
 *
 *   error -> session -> routing -> body parsing -> CSRF -> route middleware
 *
 * Session sits inside the error middleware so a session_start() failure is
 * rendered rather than surfacing as a raw PHP fatal, and CSRF sits inside body
 * parsing so it can read a `_token` field when no header is present.
 */
$app->add(CsrfMiddleware::class);
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->add(SessionMiddleware::class);

$debug           = (bool) config('settings.app_debug');
$errorMiddleware = $app->addErrorMiddleware($debug, true, true);

$errorMiddleware->setDefaultErrorHandler(
    new ErrorRenderer($app->getCallableResolver(), $app->getResponseFactory())
);

require dirname(__DIR__) . '/routes/web.php';

return $app;
