<?php

declare(strict_types=1);

use App\Database\Schema;
use App\Database\Seeder;
use App\Middleware\SessionMiddleware;
use Monolog\ErrorHandler;
use Slim\Factory\AppFactory;
use Slim\Handlers\ErrorHandler as SlimErrorHandler;

$container = require __DIR__ . '/container.php';
AppFactory::setContainer($container);
$app = AppFactory::create();

// Capture all PHP errors, warnings, and fatal errors into the log file
ErrorHandler::register(logger());

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

$debug          = config('settings.app_debug');
$errorMiddleware = $app->addErrorMiddleware($debug, true, true);

// Log every unhandled exception, then delegate to Slim's default renderer
$errorMiddleware->setDefaultErrorHandler(
    function (
        \Psr\Http\Message\ServerRequestInterface $request,
        \Throwable $exception,
        bool $displayErrorDetails,
    ) use ($app): \Psr\Http\Message\ResponseInterface {
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

Schema::migrate();

$app->add(SessionMiddleware::class);

require __DIR__ . '/database.php';

Seeder::seed();

require dirname(__DIR__) . '/routes/web.php';

return $app;
