<?php

declare(strict_types=1);

namespace App\Support\RetroAchievements;

use App\Models\RaConsoleSync;
use App\Models\RaGame;
use App\Support\ScreenScraperQuota;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * How current the RetroAchievements data is.
 *
 * Deliberately not a quota. RetroAchievements answers with achievements, not
 * with an allowance — nothing in a response says how many more calls today is
 * good for, so there is no number to read back the way {@see ScreenScraperQuota}
 * reads one out of ScreenScraper's `ssuser` block. The honest counterpart is
 * freshness: whether every console the library needs has a hash index, how old
 * the stalest one is, and how much identified work is still waiting for a set.
 *
 * Cached for a minute because the sidebar renders on every page and this is
 * three queries. A minute of lag on a figure that moves nightly is invisible;
 * the queries are not.
 */
final class SyncFreshness
{
    private const KEY = 'retroachievements.freshness';

    private const TTL = 60;

    /**
     * Index coverage and outstanding set work.
     *
     * `indexed_at` is the *oldest* sync among the covered consoles, not the
     * newest: the weakest link is what decides whether a game scanned today
     * can be identified, and a newest-first figure would read as fresh the
     * moment any one console was refreshed.
     *
     * @return array{consoles: int, indexed: int, indexed_at: ?Carbon, sets_pending: int}
     */
    public static function current(): array
    {
        $snapshot = Cache::remember(self::KEY, self::TTL, function (): array {
            $needed = LibraryConsoles::mapped()->keys()->all();

            $synced = RaConsoleSync::query()
                ->whereIn('ra_console_id', $needed !== [] ? $needed : [0])
                ->whereNotNull('synced_at')
                ->get(['synced_at']);

            // Stored as a string rather than a Carbon, so what comes back out
            // of the cache does not depend on how the store serialised it.
            return [
                'consoles' => count($needed),
                'indexed' => $synced->count(),
                'indexed_at' => $synced->min('synced_at')?->toIso8601String(),
                'sets_pending' => RaGame::query()->whereNull('set_synced_at')->count(),
            ];
        });

        return [
            'consoles' => $snapshot['consoles'],
            'indexed' => $snapshot['indexed'],
            'indexed_at' => $snapshot['indexed_at'] !== null ? Carbon::parse($snapshot['indexed_at']) : null,
            'sets_pending' => $snapshot['sets_pending'],
        ];
    }
}
