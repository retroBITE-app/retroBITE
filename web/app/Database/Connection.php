<?php

declare(strict_types=1);

namespace App\Database;

use PDO;

class Connection
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $dbPath = config('settings.db_path');

        $dbDir = dirname($dbPath);
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0755, true);
        }

        self::$instance = new PDO(
            dsn: "sqlite:{$dbPath}",
            options: [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        // WAL mode for better read concurrency with multiple PHP workers
        self::$instance->exec('PRAGMA journal_mode=WAL');
        self::$instance->exec('PRAGMA foreign_keys=ON');

        return self::$instance;
    }
}
