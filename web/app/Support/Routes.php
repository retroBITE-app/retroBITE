<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Reads config/routes.php: the FastRoute patterns for registration, and concrete
 * URLs for anything that needs to link somewhere.
 */
final class Routes
{
    /**
     * Every route definition, keyed by name.
     *
     * @return Collection<string, array{method: string, path: string, handler: array, auth?: bool}>
     */
    public static function all(): Collection
    {
        return Collection::make(config('routes', []));
    }

    /**
     * Routes inside AuthMiddleware, or those outside it.
     *
     * @return Collection<string, array{method: string, path: string, handler: array, auth?: bool}>
     */
    public static function requiringAuth(bool $auth): Collection
    {
        return self::all()->filter(
            fn(array $route) => (bool) Arr::get($route, 'auth', true) === $auth
        );
    }

    /**
     * The raw FastRoute pattern for a named route.
     *
     * Indexed off the whole map rather than a dotted config key — route names
     * contain dots, which config() would read as nesting.
     */
    public static function pattern(string $name): string
    {
        $path = Arr::get(self::all()->get($name, []), 'path');

        if (!is_string($path)) {
            throw new InvalidArgumentException("Unknown route: {$name}");
        }

        return $path;
    }

    /**
     * A concrete URL, substituting each placeholder and URL-encoding its value.
     *
     * @param array<string, string|int> $params
     */
    public static function url(string $name, array $params = []): string
    {
        $url = self::pattern($name);

        foreach ($params as $key => $value) {
            // A catch-all placeholder spans several segments, so its slashes must
            // survive encoding; every other value is a single segment.
            $url = str_replace(
                '{' . $key . ':.+}',
                self::encodePath((string) $value),
                $url,
            );

            $url = str_replace('{' . $key . '}', rawurlencode((string) $value), $url);
        }

        if (str_contains($url, '{')) {
            throw new InvalidArgumentException("Route {$name} is missing a parameter: {$url}");
        }

        return $url;
    }

    /**
     * Encode a multi-segment value, keeping the separators intact.
     */
    private static function encodePath(string $value): string
    {
        return implode('/', Arr::map(explode('/', $value), fn(string $segment): string => rawurlencode($segment)));
    }
}
