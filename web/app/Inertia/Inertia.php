<?php

declare(strict_types=1);

namespace App\Inertia;

use App\Helpers\Vite;
use Closure;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Server half of the Inertia protocol: builds the page object, serves it as JSON
 * to an Inertia visit and as an HTML shell on a cold load.
 */
class Inertia
{
    private const HEADER_INERTIA           = 'X-Inertia';
    private const HEADER_VERSION           = 'X-Inertia-Version';
    private const HEADER_LOCATION          = 'X-Inertia-Location';
    private const HEADER_PARTIAL_DATA      = 'X-Inertia-Partial-Data';
    private const HEADER_PARTIAL_COMPONENT = 'X-Inertia-Partial-Component';

    /** Methods a redirect must answer with 303 so the browser re-issues it as GET. */
    private const SEE_OTHER_METHODS = ['PUT', 'PATCH', 'DELETE'];

    private static array $shared = [];

    /**
     * Merge props into every subsequent Inertia response this request renders.
     */
    public static function share(array $props): void
    {
        self::$shared = array_merge(self::$shared, $props);
    }

    /**
     * Render a page, as JSON for an Inertia visit or as the HTML shell otherwise.
     *
     * Props whose value is a Closure are only evaluated when the page actually
     * ships them, so a partial reload asking for one prop no longer pays for all
     * of them.
     */
    public static function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $component,
        array $props = [],
    ): ResponseInterface {
        if ($stale = self::versionConflict($request, $response)) {
            return $stale;
        }

        $page = [
            'component' => $component,
            'props'     => self::resolveProps($request, $component, array_merge(self::$shared, $props)),
            'url'       => self::currentUrl($request),
            'version'   => Vite::version(),
        ];

        return self::isInertia($request)
            ? self::asJson($response, $page)
            : self::asDocument($response, $page);
    }

    /**
     * Redirect after a form submission, using 303 where the browser would
     * otherwise replay a non-GET method against the new location.
     */
    public static function redirect(
        ResponseInterface $response,
        string $url,
        string $method = 'GET',
    ): ResponseInterface {
        $status = in_array(strtoupper($method), self::SEE_OTHER_METHODS, true) ? 303 : 302;

        return $response->withHeader('Location', $url)->withStatus($status);
    }

    /**
     * Force a full page load, for destinations Inertia cannot own (or an expired
     * session bouncing the visitor to the login page).
     */
    public static function location(ResponseInterface $response, string $url): ResponseInterface
    {
        return $response->withHeader(self::HEADER_LOCATION, $url)->withStatus(409);
    }

    /**
     * Is this an Inertia visit rather than a cold browser load?
     */
    private static function isInertia(ServerRequestInterface $request): bool
    {
        return $request->getHeaderLine(self::HEADER_INERTIA) !== '';
    }

    /**
     * A 409 telling a client running stale assets to hard-reload, or null when its
     * version matches ours.
     */
    private static function versionConflict(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ?ResponseInterface {
        if (!self::isInertia($request) || strtoupper($request->getMethod()) !== 'GET') {
            return null;
        }

        $clientVersion = $request->getHeaderLine(self::HEADER_VERSION);

        if ($clientVersion === '' || $clientVersion === Vite::version()) {
            return null;
        }

        return self::location($response, self::currentUrl($request));
    }

    /**
     * Narrow the props to those a partial reload asked for, then evaluate any
     * lazily-declared ones.
     */
    private static function resolveProps(
        ServerRequestInterface $request,
        string $component,
        array $props,
    ): array {
        $only = self::partialKeys($request, $component);

        if ($only !== []) {
            $props = Arr::only($props, $only);
        }

        return Arr::map($props, fn(mixed $value) => $value instanceof Closure ? $value() : $value);
    }

    /**
     * Prop names a partial reload requested, or [] when it wants the whole page.
     *
     * @return string[]
     */
    private static function partialKeys(ServerRequestInterface $request, string $component): array
    {
        $requested = $request->getHeaderLine(self::HEADER_PARTIAL_DATA);

        if ($requested === '' || $request->getHeaderLine(self::HEADER_PARTIAL_COMPONENT) !== $component) {
            return [];
        }

        return array_values(Arr::where(
            Arr::map(explode(',', $requested), fn(string $key): string => trim($key)),
            fn(string $key): bool => $key !== '',
        ));
    }

    /**
     * Path and query of the current request, as Inertia records it in history.
     */
    private static function currentUrl(ServerRequestInterface $request): string
    {
        $uri   = $request->getUri();
        $query = $uri->getQuery();

        return ($uri->getPath() ?: '/') . ($query === '' ? '' : '?' . $query);
    }

    /**
     * The page object as an Inertia JSON response.
     */
    private static function asJson(ResponseInterface $response, array $page): ResponseInterface
    {
        $response->getBody()->write(self::encode($page));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader(self::HEADER_INERTIA, 'true')
            ->withHeader('Vary', self::HEADER_INERTIA);
    }

    /**
     * The page object embedded in the root HTML template.
     */
    private static function asDocument(ResponseInterface $response, array $page): ResponseInterface
    {
        $response->getBody()->write(
            Document::render(htmlspecialchars(self::encode($page), ENT_QUOTES, 'UTF-8'))
        );

        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Encode the page object, throwing rather than emitting false.
     */
    private static function encode(array $page): string
    {
        return json_encode($page, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
