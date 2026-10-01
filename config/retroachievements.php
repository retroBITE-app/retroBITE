<?php

/*
 * Every fallback below is written `env(...) ?: default` rather than passed as
 * env()'s second argument, because that argument only applies when the
 * variable is *absent*. A variable present but empty — which is exactly what
 * copying .env.example produces — returns the empty string, and the default
 * never runs.
 *
 * It cost an afternoon: RA_HASHER_PATH resolved to '', proc_open was handed an
 * empty program name, and four hundred hash jobs failed with a ValueError from
 * deep inside Symfony that said nothing about the cause.
 */
return [
    'endpoint' => env('RETROACHIEVEMENTS_ENDPOINT') ?: 'https://retroachievements.org/API',

    /*
     * Where badge images live. Kept apart from the API endpoint because it is a
     * plain CDN with no key in the URL, and because badges are the one part of
     * the integration that reaches the network at render time.
     */
    'media_url' => env('RETROACHIEVEMENTS_MEDIA_URL') ?: 'https://media.retroachievements.org',

    /*
     * Empty on purpose, as ScreenScraper's account is. The key lives in
     * AppSetting::RA_API_KEY and is set in Settings; this remains only so a
     * test can stand one up without the database. A key somebody has to
     * redeploy to change is a key they will not change.
     */
    'api_key_fallback' => '',

    /*
     * As above: only the migration that moves the key into the settings table
     * reads this, and only on an install that still has the variable set.
     */
    'legacy_env_api_key' => env('RETROACHIEVEMENTS_API_KEY', ''),

    'connect_timeout' => (int) (env('RETROACHIEVEMENTS_CONNECT_TIMEOUT') ?: 15),

    /*
     * Generous because of one endpoint: GetGameList with h=1 returns every
     * hash RetroAchievements knows for a console, which for PlayStation and
     * SNES is megabytes of JSON.
     */
    'timeout' => (int) (env('RETROACHIEVEMENTS_TIMEOUT') ?: 180),

    /*
     * Seconds to leave between two API calls, across every worker.
     * RetroAchievements publishes no number, but Cloudflare in front of it
     * does the counting: on 2026-10-01 about twenty calls in five seconds
     * met a 429 and two minutes' block, every time — ten sets a minute,
     * however fast they were asked for. Three seconds is twenty a minute
     * without ever meeting it. Set to 0 to disable, which the test suite does.
     */
    // Not ?: here — 0 is a legitimate value that means "do not pace at all",
    // and the test suite relies on setting it.
    'min_interval' => (float) (env('RETROACHIEVEMENTS_MIN_INTERVAL') ?? 3.0),

    /*
     * RAHasher, built into the web image from RALibretro. A bare name is
     * looked up on PATH, which is how the container finds it; a developer on
     * macOS points this at their own build.
     */
    'hasher_path' => env('RA_HASHER_PATH') ?: 'RAHasher',

    /* A CHD on a slow disk takes minutes. Kept under the queue's retry_after. */
    'hasher_timeout' => (int) (env('RA_HASHER_TIMEOUT') ?: 1800),

    /*
     * Extra minutes added to the recent-unlocks window, so a worker that
     * starts a few seconds late does not leave a gap no later run will cover.
     */
    'recent_margin' => (int) (env('RETROACHIEVEMENTS_RECENT_MARGIN') ?: 30),
];
