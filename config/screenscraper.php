<?php

use App\Support\Obfuscated;

return [
    'dev_id' => Obfuscated::reveal('AAQfHw4='),
    'dev_password' => Obfuscated::reveal('ICkQAFwOCCY9KDw='),
    /*
     * Empty on purpose. The account lives in the settings table, read through
     * App\Support\ScreenScraperCredentials — these two remain only so a test
     * can stand one up without touching the database, and nothing reads them
     * from the environment.
     */
    'user' => '',
    'password' => '',

    /*
     * Where the account used to be read from, kept only so the migration that
     * moves it into the settings table can find it on an install that still
     * has it. Read through config rather than env() in the migration itself,
     * because a container with a cached config never loads the .env file at
     * all and env() would hand back null. Removable once no install predates
     * the move.
     */
    'legacy_env_user' => env('SCREENSCRAPER_USER', ''),
    'legacy_env_password' => env('SCREENSCRAPER_PASSWORD', ''),
    'endpoint' => env('SCREENSCRAPER_ENDPOINT', 'https://api.screenscraper.fr/api2'),

    /*
     * `?:` rather than env()'s default, because .env.example lists these keys
     * with no value, and a key present but empty comes back as '' — which
     * (int) turns into 0, and 0 is Guzzle for "wait forever".
     */
    'connect_timeout' => (int) (env('SCREENSCRAPER_CONNECT_TIMEOUT') ?: 15),

    /*
     * A jeuInfos answer for a game with a lot of media runs past a megabyte,
     * and on a slow day that has taken over a minute. Kept, with the connect
     * timeout and the pacing, under MatchGame's 120.
     */
    'timeout' => (int) (env('SCREENSCRAPER_TIMEOUT') ?: 90),

    /*
     * Seconds to leave between two API calls. Not a guess: ScreenScraper asked
     * scraper authors for at least one, and Skyscraper has shipped 1.2 with a
     * "don't change this" comment ever since. Set to 0 to disable, which the
     * test suite does so it is not paced by a sleep.
     */
    'min_interval' => (float) env('SCREENSCRAPER_MIN_INTERVAL', 1.2),
];
