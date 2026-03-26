<?php

declare(strict_types=1);

namespace App\Database;

class Schema
{
    public static function migrate(): void
    {
        $pdo           = Connection::get();
        $migrationsDir = config('settings.migrations_path');

        // Track which migrations have been applied
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS migrations (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                filename   TEXT    NOT NULL UNIQUE,
                applied_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now'))
            )
        ");

        $applied = array_flip(
            $pdo->query('SELECT filename FROM migrations')->fetchAll(\PDO::FETCH_COLUMN)
        );

        $files = glob($migrationsDir . '/*.sql') ?: [];
        sort($files);

        foreach ($files as $file) {
            $filename = basename($file);

            if (isset($applied[$filename])) {
                continue;
            }

            $pdo->exec((string) file_get_contents($file));

            $stmt = $pdo->prepare('INSERT INTO migrations (filename) VALUES (?)');
            $stmt->execute([$filename]);
        }
    }
}
