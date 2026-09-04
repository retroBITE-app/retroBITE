<?php

declare(strict_types=1);

namespace App\Database;

use Throwable;

/**
 * Brings the database up to date exactly once, whatever the concurrency. Migrations
 * run on the request path, so two workers on a fresh volume would otherwise both
 * apply the same file; an exclusive file lock serialises them.
 */
final class Bootstrap
{
    private const LOCK_FILE = '/migrate.lock';

    /**
     * Apply pending migrations and seed the initial user, under a cross-process lock.
     */
    public static function prepare(): void
    {
        $handle = self::acquireLock();

        try {
            Schema::migrate();
            Seeder::seed();
        } finally {
            if ($handle !== null) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    /**
     * Take the exclusive lock, blocking until free. Returns null when the lock file
     * cannot be opened — an unwritable storage dir loses serialisation, not boot.
     *
     * @return resource|null
     */
    private static function acquireLock()
    {
        $path = config('settings.storage_path') . self::LOCK_FILE;

        try {
            $handle = @fopen($path, 'c');

            if ($handle === false) {
                throw new \RuntimeException("Could not open lock file: {$path}");
            }

            flock($handle, LOCK_EX);

            return $handle;
        } catch (Throwable $e) {
            logger()->warning('Running migrations without a lock', ['message' => $e->getMessage()]);

            return null;
        }
    }
}
