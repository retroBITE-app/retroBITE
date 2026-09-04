<?php

declare(strict_types=1);

use App\Database\Connection;
use Illuminate\Database\Capsule\Manager as Capsule;

$capsule = new Capsule;

$capsule->addConnection([
    'driver'   => 'sqlite',
    // Connection::path() creates the file if needed — the SQLite connector rejects
    // a path that does not already resolve.
    'database' => Connection::path(),
    'prefix'   => '',
]);

$capsule->setAsGlobal();
$capsule->bootEloquent();

// Same pragmas as the raw PDO connection used by the migration runner.
Capsule::statement('PRAGMA journal_mode=WAL');
Capsule::statement('PRAGMA foreign_keys=ON');
