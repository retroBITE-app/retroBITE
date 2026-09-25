<?php

declare(strict_types=1);

namespace App\Support;

use App\Events\SystemUpdated;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * The account's current ScreenScraper allowance, as the last response reported it.
 *
 * Every response carries an `ssuser` block describing what this account may do
 * today, and the numbers differ per account — a plain registered login gets one
 * thread and 20 000 requests, a contributor gets far more. So there is nothing
 * to hard-code: the limits are read back from whatever the server just said.
 *
 * This lives in the cache rather than a table because it is a passing fact
 * about today, not a record worth keeping. The cache store is the database, so
 * it survives a restart, which is all the durability it needs.
 */
final class ScreenScraperQuota
{
    private const KEY = 'screenscraper.quota';

    /** Longer than a day, so a stale snapshot is visible rather than silently absent. */
    private const TTL = 172800;

    /**
     * Record what a response said about the account and the server.
     *
     * @param  array<string, mixed>  $response  the decoded `response` object
     */
    public static function remember(array $response): void
    {
        $user = Arr::get($response, 'ssuser');

        if (! is_array($user)) {
            return;
        }

        Cache::put(self::KEY, [
            'max_threads' => (int) Arr::get($user, 'maxthreads', 1),
            'requests_today' => (int) Arr::get($user, 'requeststoday', 0),
            'max_requests_per_day' => (int) Arr::get($user, 'maxrequestsperday', 0),
            'failed_today' => (int) Arr::get($user, 'requestskotoday', 0),
            'max_failed_per_day' => (int) Arr::get($user, 'maxrequestskoperday', 0),
            'max_download_speed' => (int) Arr::get($user, 'maxdownloadspeed', 0),
            'level' => (int) Arr::get($user, 'niveau', 0),
            // Server-side load shedding. Non-zero means ScreenScraper is turning
            // away accounts like ours, which is worth showing before a scan
            // starts rather than discovering one 401 at a time.
            'closed_for_non_members' => (bool) (int) Arr::get($response, 'serveurs.closefornomember', 0),
            'closed_for_leechers' => (bool) (int) Arr::get($response, 'serveurs.closeforleecher', 0),
            'recorded_at' => now()->toIso8601String(),
        ], self::TTL);

        // The allowance moves with every request, and nothing else moves it:
        // this is the only moment the sidebar's figure goes stale.
        LiveUpdates::system(SystemUpdated::QUOTA);
    }

    /**
     * The last recorded snapshot, or null when nothing has been recorded yet.
     *
     * Rebuilt field by field rather than returned as it came out: the cache is
     * shared with whatever version of this code wrote the entry, so a snapshot
     * written before a field existed would otherwise surface as a missing key
     * at the call site.
     *
     * @return array{max_threads: int, requests_today: int, max_requests_per_day: int, failed_today: int, max_failed_per_day: int, max_download_speed: int, level: int, closed_for_non_members: bool, closed_for_leechers: bool, recorded_at: string}|null
     */
    public static function current(): ?array
    {
        $snapshot = Cache::get(self::KEY);

        if (! is_array($snapshot)) {
            return null;
        }

        return [
            'max_threads' => (int) ($snapshot['max_threads'] ?? 1),
            'requests_today' => (int) ($snapshot['requests_today'] ?? 0),
            'max_requests_per_day' => (int) ($snapshot['max_requests_per_day'] ?? 0),
            'failed_today' => (int) ($snapshot['failed_today'] ?? 0),
            'max_failed_per_day' => (int) ($snapshot['max_failed_per_day'] ?? 0),
            'max_download_speed' => (int) ($snapshot['max_download_speed'] ?? 0),
            'level' => (int) ($snapshot['level'] ?? 0),
            'closed_for_non_members' => (bool) ($snapshot['closed_for_non_members'] ?? false),
            'closed_for_leechers' => (bool) ($snapshot['closed_for_leechers'] ?? false),
            'recorded_at' => (string) ($snapshot['recorded_at'] ?? ''),
        ];
    }

    /**
     * Successful requests left today, or null when nothing has been recorded yet.
     *
     * Null is not zero: it means we have not asked, and the caller should go
     * ahead rather than refuse to start.
     */
    public static function remaining(): ?int
    {
        $s = self::current();

        if ($s === null || $s['max_requests_per_day'] === 0) {
            return null;
        }

        $left = $s['max_requests_per_day'] - $s['requests_today'];

        return $left > 0 ? $left : 0;
    }

    /**
     * Failed lookups left today.
     *
     * Ten times scarcer than the ordinary allowance (2 000 against 20 000 on a
     * plain account), so this is the one that runs out first on a library full
     * of unrecognised dumps.
     */
    public static function failedRemaining(): ?int
    {
        $s = self::current();

        if ($s === null || $s['max_failed_per_day'] === 0) {
            return null;
        }

        $left = $s['max_failed_per_day'] - $s['failed_today'];

        return $left > 0 ? $left : 0;
    }

    public static function forget(): void
    {
        Cache::forget(self::KEY);
    }
}
