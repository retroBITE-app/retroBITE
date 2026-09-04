<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

/**
 * Raw PDO handle for the migration runner. Everything else goes through Eloquent.
 */
class Connection
{
    private static ?PDO $instance = null;

    /**
     * The shared PDO handle, opened on first use.
     */
    public static function get(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        self::$instance = new PDO(
            dsn: 'sqlite:' . self::path(),
            options: [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        self::applyPragmas(self::$instance);

        return self::$instance;
    }

    /**
     * Path to the SQLite file, creating its directory and the file itself if absent.
     * Eloquent's SQLite connector refuses a path that does not resolve, so the file
     * has to exist before the Capsule boots.
     */
    public static function path(): string
    {
        $path = (string) config('settings.db_path');
        $dir  = dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create the database directory: {$dir}");
        }

        if (!file_exists($path) && @touch($path) === false) {
            throw new RuntimeException("Could not create the database file: {$path}");
        }

        return $path;
    }

    /**
     * WAL for read concurrency across PHP workers, plus foreign-key enforcement.
     */
    private static function applyPragmas(PDO $pdo): void
    {
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
    }
}
