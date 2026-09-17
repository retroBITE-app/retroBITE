<?php

use App\Support\Obfuscated;

return [
    'dev_id' => Obfuscated::reveal('AAQfHw4='),
    'dev_password' => Obfuscated::reveal('ICkQAFwOCCY9KDw='),
    'user' => env('SCREENSCRAPER_USER', ''),
    'password' => env('SCREENSCRAPER_PASSWORD', ''),
    'endpoint' => env('SCREENSCRAPER_ENDPOINT', 'https://api.screenscraper.fr/api2'),
    'connect_timeout' => (int) env('SCREENSCRAPER_CONNECT_TIMEOUT', 15),
    'timeout' => (int) env('SCREENSCRAPER_TIMEOUT', 45),

    /*
     * Seconds to leave between two API calls. ScreenScraper asks scraper
     * authors for at least one; 1.2 is what the established clients use. Set to
     * 0 to disable, which the test suite does so it is not paced by a sleep.
     */
    'min_interval' => (float) env('SCREENSCRAPER_MIN_INTERVAL', 1.2),
];
