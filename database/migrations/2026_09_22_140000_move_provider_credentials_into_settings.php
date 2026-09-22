<?php

use App\Models\AppSetting;
use Illuminate\Database\Migrations\Migration;

/**
 * Carry the provider credentials out of the environment and into settings.
 *
 * They used to be read from SCREENSCRAPER_USER, SCREENSCRAPER_PASSWORD and
 * RETROACHIEVEMENTS_API_KEY. Nothing reads those any more — a newcomer should
 * not have to edit a file before the library can identify anything, and a
 * credential you must redeploy to change is one nobody changes.
 *
 * This is the upgrade path for an install that already has them. It runs once,
 * takes whatever is still in the environment, and never overwrites something
 * already set here: a key typed into Settings is the newer answer of the two.
 *
 * Read through config rather than env(): a container with a cached config
 * never loads the .env file, and env() would hand back null there — which
 * would look exactly like an install that had nothing to carry over.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Never in the suite. This is the one migration that reads the
        // environment, and the environment the suite runs in is whoever's
        // machine it is — a developer's own ScreenScraper login would be
        // seeded into every test database and silently outrank the fixtures,
        // which is the same trap phpunit.xml already guards DB_DATABASE from.
        if (app()->environment('testing')) {
            return;
        }

        if (AppSetting::get(AppSetting::SS_USER) === null && ($user = trim((string) config('screenscraper.legacy_env_user', ''))) !== '') {
            AppSetting::put(AppSetting::SS_USER, $user);
        }

        if (AppSetting::getSecret(AppSetting::SS_PASSWORD) === null && ($password = trim((string) config('screenscraper.legacy_env_password', ''))) !== '') {
            AppSetting::putSecret(AppSetting::SS_PASSWORD, $password);
        }

        if (AppSetting::getSecret(AppSetting::RA_API_KEY) === null && ($key = trim((string) config('retroachievements.legacy_env_api_key', ''))) !== '') {
            AppSetting::putSecret(AppSetting::RA_API_KEY, $key);
        }

        AppSetting::flush();
    }

    /**
     * Nothing.
     *
     * Rolling back would leave an install with no credentials at all rather
     * than with the ones it had: the environment they came from may well have
     * been cleaned out by then, and this migration is the only copy.
     */
    public function down(): void
    {
        //
    }
};
