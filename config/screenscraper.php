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
];