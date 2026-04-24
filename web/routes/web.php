<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\ConsoleController;
use App\Controllers\DashboardController;
use App\Controllers\GameController;
use App\Controllers\NetworkController;
use App\Controllers\SettingsController;
use App\Middleware\AuthMiddleware;
use Slim\Routing\RouteCollectorProxy;

// Public auth routes
$app->get('/login',  [AuthController::class, 'showLogin']);
$app->post('/login', [AuthController::class, 'login']);
$app->post('/logout', [AuthController::class, 'logout']);

// Protected routes
$app->group('', function (RouteCollectorProxy $group) {
    $group->get('/', [DashboardController::class, 'index']);

    $group->get('/consoles', [ConsoleController::class, 'index']);
    $group->get('/consoles/{console}', [ConsoleController::class, 'show']);
    $group->get('/consoles/{console}/{game}', [GameController::class, 'show']);
    $group->post('/consoles/{console}/{game}/identify', [GameController::class, 'identify']);
    $group->post('/consoles/{console}/{game}/metadata', [GameController::class, 'assignMetadata']);
    $group->delete('/consoles/{console}/{game}', [GameController::class, 'destroy']);
    $group->post('/consoles/{console}/scan', [ConsoleController::class, 'scan']);
    $group->post('/consoles/{console}/upload-chunk', [ConsoleController::class, 'uploadChunk']);
    $group->post('/consoles/{console}/mkdir', [ConsoleController::class, 'mkdir']);
    $group->delete('/consoles/{console}/folder/{folder:.+}', [ConsoleController::class, 'deleteFolder']);

    $group->get('/api/network/status', [NetworkController::class, 'status']);

    $group->get('/settings',                         [SettingsController::class, 'index']);
    $group->post('/settings/{group}/{key}',          [SettingsController::class, 'save']);
    $group->post('/settings/{group}/{key}/reset',    [SettingsController::class, 'reset']);
})->add(AuthMiddleware::class);
