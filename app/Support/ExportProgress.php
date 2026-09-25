<?php

declare(strict_types=1);

namespace App\Support;

use App\Events\SystemUpdated;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * How far through an export the worker is, for the page that is watching it.
 *
 * The jobs table can only say an export is queued, never that it is on its
 * twelfth disc of nineteen — one job writes the whole console. So the worker
 * leaves its count here as it goes and the sidebar reads it back.
 *
 * Cache rather than a table: it is worth nothing once the export is over, and
 * a worker killed mid-run must not leave a bar on somebody's screen for ever.
 * Every entry expires on its own.
 */
final class ExportProgress
{
    /**
     * One key for all of them, keyed inside by console and export.
     *
     * Read, changed and written back without a lock, so two exports running
     * on two media workers at once can each drop the other's latest count.
     * It is back on that export's next file, and a progress figure one file
     * behind for a moment is not worth a lock around every write.
     */
    private const KEY = 'exports.progress';

    /** Just past WriteConsoleExports::$timeout, so a killed worker's entry goes by itself. */
    private const TTL = 1860;

    /** Say how many of the total have been dealt with. */
    public static function advance(string $console, string $export, int $done, int $total): void
    {
        self::write($console, $export, $done, $total);

        LiveUpdates::system(SystemUpdated::ACTIVITY);
    }

    /** Take an export off the page. Called however the job ends. */
    public static function finish(string $console, string $export): void
    {
        try {
            self::forgetEntry($console, $export);
        } finally {
            LiveUpdates::system(SystemUpdated::ACTIVITY);
        }
    }

    private static function forgetEntry(string $console, string $export): void
    {
        $entries = self::entries();

        Arr::forget($entries, self::entryKey($console, $export));

        if ($entries === []) {
            Cache::forget(self::KEY);

            return;
        }

        Cache::put(self::KEY, $entries, self::TTL);
    }

    /**
     * Every export currently running.
     *
     * @return array<int, array{console: string, export: string, done: int, total: int}>
     */
    public static function all(): array
    {
        return array_values(self::entries());
    }

    /**
     * @return array<string, array{console: string, export: string, done: int, total: int}>
     */
    private static function entries(): array
    {
        $entries = Cache::get(self::KEY, []);

        return is_array($entries) ? $entries : [];
    }

    private static function write(string $console, string $export, int $done, int $total): void
    {
        $entries = self::entries();

        Arr::set($entries, self::entryKey($console, $export), [
            'console' => $console,
            'export' => $export,
            'done' => $done,
            'total' => $total,
        ]);

        Cache::put(self::KEY, $entries, self::TTL);
    }

    /** Dots are Arr::set's path separator, so the key cannot carry one. */
    private static function entryKey(string $console, string $export): string
    {
        return str_replace('.', '_', $console.'-'.$export);
    }
}
