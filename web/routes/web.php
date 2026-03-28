<?php

declare(strict_types=1);

use App\Controllers\DashboardController;
use App\Controllers\ConsoleController;
use App\Controllers\GameController;

$app->get('/', [DashboardController::class, 'index']);

$app->get('/consoles', [ConsoleController::class, 'index']);
$app->get('/consoles/{console}', [ConsoleController::class, 'show']);
$app->get('/consoles/{console}/{game}', [GameController::class, 'show']);
$app->post('/consoles/{console}/scan', [ConsoleController::class, 'scan']);
$app->post('/consoles/{console}/upload-chunk', [ConsoleController::class, 'uploadChunk']);
$app->post('/consoles/{console}/mkdir', [ConsoleController::class, 'mkdir']);
