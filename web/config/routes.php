<?php

declare(strict_types=1);

/**
 * The single definition of every route.
 *
 * routes/web.php registers these, the route() helper builds URLs from them, and
 * `php console routes:ts` emits resources/js/routes.ts from the same list — so a
 * path exists in exactly one place instead of being retyped in 23 Vue files.
 *
 * `path` uses FastRoute placeholders. `auth` false means the route sits outside
 * AuthMiddleware.
 */

use App\Controllers\AuthController;
use App\Controllers\ConsoleController;
use App\Controllers\DashboardController;
use App\Controllers\GameController;
use App\Controllers\NetworkController;
use App\Controllers\SettingsController;

return [
    'login'        => ['method' => 'GET',  'path' => '/login',  'handler' => [AuthController::class, 'showLogin'], 'auth' => false],
    'login.submit' => ['method' => 'POST', 'path' => '/login',  'handler' => [AuthController::class, 'login'],     'auth' => false],
    'logout'       => ['method' => 'POST', 'path' => '/logout', 'handler' => [AuthController::class, 'logout']],

    'dashboard' => ['method' => 'GET', 'path' => '/', 'handler' => [DashboardController::class, 'index']],

    'consoles'               => ['method' => 'GET',    'path' => '/consoles',                                 'handler' => [ConsoleController::class, 'index']],
    'console'                => ['method' => 'GET',    'path' => '/consoles/{console}',                       'handler' => [ConsoleController::class, 'show']],
    'console.scan'           => ['method' => 'POST',   'path' => '/consoles/{console}/scan',                  'handler' => [ConsoleController::class, 'scan']],
    'console.uploadChunk'    => ['method' => 'POST',   'path' => '/consoles/{console}/upload-chunk',          'handler' => [ConsoleController::class, 'uploadChunk']],
    'console.mkdir'          => ['method' => 'POST',   'path' => '/consoles/{console}/mkdir',                 'handler' => [ConsoleController::class, 'mkdir']],
    'console.folder.destroy' => ['method' => 'DELETE', 'path' => '/consoles/{console}/folder/{folder:.+}',    'handler' => [ConsoleController::class, 'deleteFolder']],

    'game'          => ['method' => 'GET',    'path' => '/consoles/{console}/{game}',          'handler' => [GameController::class, 'show']],
    'game.identify' => ['method' => 'POST',   'path' => '/consoles/{console}/{game}/identify', 'handler' => [GameController::class, 'identify']],
    'game.metadata' => ['method' => 'POST',   'path' => '/consoles/{console}/{game}/metadata', 'handler' => [GameController::class, 'assignMetadata']],
    'game.move'     => ['method' => 'POST',   'path' => '/consoles/{console}/{game}/move',     'handler' => [GameController::class, 'move']],
    'game.destroy'  => ['method' => 'DELETE', 'path' => '/consoles/{console}/{game}',          'handler' => [GameController::class, 'destroy']],

    'network.status' => ['method' => 'GET', 'path' => '/api/network/status', 'handler' => [NetworkController::class, 'status']],

    'settings'       => ['method' => 'GET',  'path' => '/settings',                       'handler' => [SettingsController::class, 'index']],
    'settings.save'  => ['method' => 'POST', 'path' => '/settings/{group}/{key}',         'handler' => [SettingsController::class, 'save']],
    'settings.reset' => ['method' => 'POST', 'path' => '/settings/{group}/{key}/reset',   'handler' => [SettingsController::class, 'reset']],
];
