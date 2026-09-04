<?php

declare(strict_types=1);

use App\Support\Routes;

if (!function_exists('route')) {
    /**
     * URL for a named route from config/routes.php.
     *
     * @param array<string, string|int> $params
     */
    function route(string $name, array $params = []): string
    {
        return Routes::url($name, $params);
    }
}
