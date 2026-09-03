<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use Throwable;

/**
 * Forward-only migration runner over the raw `.sql` files in database/migrations.
 */
class Schema
{
    /**
     * Apply every unrecorded migration in filename order, each in its own transaction
     * so a part-way failure cannot leave a DROP-then-CREATE file having only dropped.
     *
     * @throws Throwable Re-thrown after rollback.
     */
    public static function migrate(): void
    {
        $pdo = Connection::get();

        self::ensureLedger($pdo);

        $applied = self::appliedFilenames($pdo);

        foreach (self::migrationFiles() as $file) {
            $filename = basename($file);

            if (isset($applied[$filename])) {
                continue;
            }

            self::apply($pdo, $file, $filename);
        }
    }

    /**
     * Create the table tracking which migrations have run.
     */
    private static function ensureLedger(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS migrations (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                filename   TEXT    NOT NULL UNIQUE,
                applied_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now'))
            )
        ");
    }

    /**
     * Already-applied filenames, as a lookup set.
     *
     * @return array<string, int>
     */
    private static function appliedFilenames(PDO $pdo): array
    {
        return array_flip(
            $pdo->query('SELECT filename FROM migrations')->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    /**
     * Migration files in the order they must be applied.
     *
     * @return string[]
     */
    private static function migrationFiles(): array
    {
        $files = glob(config('settings.migrations_path') . '/*.sql') ?: [];
        sort($files);

        return $files;
    }

    /**
     * Run one migration and record it, atomically.
     *
     * @throws Throwable
     */
    private static function apply(PDO $pdo, string $file, string $filename): void
    {
        $pdo->beginTransaction();

        try {
            $pdo->exec((string) file_get_contents($file));

            $pdo->prepare('INSERT INTO migrations (filename) VALUES (?)')->execute([$filename]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();

            logger()->error('Migration failed and was rolled back', [
                'migration' => $filename,
                'message'   => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
