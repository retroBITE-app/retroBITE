<?php

declare(strict_types=1);

use App\Controllers\DashboardController;
use App\Controllers\ConsoleController;

$app->get('/', [DashboardController::class, 'index']);

$app->get('/consoles', [ConsoleController::class, 'index']);
$app->get('/consoles/{console}', [ConsoleController::class, 'show']);
$app->post('/consoles/{console}/scan', [ConsoleController::class, 'scan']);
