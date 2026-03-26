<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;

$capsule = new Capsule;

$capsule->addConnection([
    'driver'   => 'sqlite',
    'database' => config('settings.db_path'),
    'prefix'   => '',
]);

$capsule->setAsGlobal();
$capsule->bootEloquent();

// Apply the same SQLite pragmas used by the PDO connection
Capsule::statement('PRAGMA journal_mode=WAL');
Capsule::statement('PRAGMA foreign_keys=ON');
