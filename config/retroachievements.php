<?php

return [
    'endpoint' => env('RETROACHIEVEMENTS_ENDPOINT', 'https://retroachievements.org/API'),

    /*
     * Where badge images live. Kept apart from the API endpoint because it is a
     * plain CDN with no key in the URL, and because badges are the one part of
     * the integration that reaches the network at render time.
     */
    'media_url' => env('RETROACHIEVEMENTS_MEDIA_URL', 'https://media.retroachievements.org'),

    /*
     * Used only when AppSetting::RA_API_KEY has not been set, so a headless
     * install can be seeded from the environment and a person can still change
     * the key later in Settings without a redeploy.
     */
    'api_key_fallback' => env('RETROACHIEVEMENTS_API_KEY', ''),

    'connect_timeout' => (int) env('RETROACHIEVEMENTS_CONNECT_TIMEOUT', 15),

    /*
     * Generous because of one endpoint: GetGameList with h=1 returns every
     * hash RetroAchievements knows for a console, which for PlayStation and
     * SNES is megabytes of JSON.
     */
    'timeout' => (int) env('RETROACHIEVEMENTS_TIMEOUT', 180),

    /*
     * Seconds to leave between two API calls. RetroAchievements rate-limits
     * but publishes no number; half a second is polite and still drains a
     * console index quickly. Set to 0 to disable, which the test suite does.
     */
    'min_interval' => (float) env('RETROACHIEVEMENTS_MIN_INTERVAL', 0.5),

    /*
     * RAHasher, built into the web image from RALibretro. A bare name is
     * looked up on PATH, which is how the container finds it; a developer on
     * macOS points this at their own build.
     */
    'hasher_path' => env('RA_HASHER_PATH', 'RAHasher'),

    /* A CHD on a slow disk takes minutes. Kept under the queue's retry_after. */
    'hasher_timeout' => (int) env('RA_HASHER_TIMEOUT', 1800),

    /*
     * Extra minutes added to the recent-unlocks window, so a worker that
     * starts a few seconds late does not leave a gap no later run will cover.
     */
    'recent_margin' => (int) env('RETROACHIEVEMENTS_RECENT_MARGIN', 30),
];
