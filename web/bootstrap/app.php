<?php

declare(strict_types=1);

use App\Database\Bootstrap;
use App\Middleware\CsrfMiddleware;
use App\Middleware\SessionMiddleware;
use Monolog\ErrorHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Factory\AppFactory;
use Slim\Handlers\ErrorHandler as SlimErrorHandler;

$container = require __DIR__ . '/container.php';
AppFactory::setContainer($container);
$app = AppFactory::create();

// Capture all PHP errors, warnings, and fatal errors into the log file
ErrorHandler::register(logger());

// Boot Eloquent, then bring the schema and seed data up to date under a lock.
require __DIR__ . '/database.php';
Bootstrap::prepare();

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

// Log every unhandled exception, then delegate to Slim's default renderer
$errorMiddleware->setDefaultErrorHandler(
    function (
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
    ) use ($app): ResponseInterface {
        logger()->error($exception->getMessage(), [
            'exception' => get_class($exception),
            'file'      => $exception->getFile(),
            'line'      => $exception->getLine(),
            'url'       => (string) $request->getUri(),
        ]);

        $handler = new SlimErrorHandler($app->getCallableResolver(), $app->getResponseFactory());

        return $handler->__invoke($request, $exception, $displayErrorDetails, true, true);
    }
);

require dirname(__DIR__) . '/routes/web.php';

return $app;
