<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AppSetting;

/**
 * The ScreenScraper account, from wherever it is kept.
 *
 * One reader, because there are four: the service that calls the API, the
 * sidebar that says whether an account is configured, the diagnostic command
 * and the settings screen. They disagreed the moment the account moved out of
 * the environment, and the disagreement looked like a login that had stopped
 * working rather than a page reading the wrong place.
 *
 * The account lives in the settings table. config/screenscraper.php still
 * carries empty defaults so a test can set one without a database, but nothing
 * reads them from the environment any more: an account somebody has to redeploy
 * to change is an account they will not change, and a newcomer should not have
 * to edit a file before the library can identify anything.
 */
final class ScreenScraperCredentials
{
    public static function user(): string
    {
        $stored = AppSetting::get(AppSetting::SS_USER);

        return trim((string) (is_string($stored) && $stored !== '' ? $stored : config('screenscraper.user', '')));
    }

    public static function password(): string
    {
        return trim((string) (AppSetting::getSecret(AppSetting::SS_PASSWORD) ?? config('screenscraper.password', '')));
    }

    /**
     * Whether a personal account is set at all.
     *
     * Not whether it works. ScreenScraper never rejects a login it does not
     * recognise — it answers on the developer account instead, with the
     * developer account's much smaller allowance — so the only way to know a
     * login was accepted is to ask, which is what the settings screen's check
     * is for.
     */
    public static function configured(): bool
    {
        return self::user() !== '';
    }
}
