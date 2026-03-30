<?php

declare(strict_types=1);

namespace App\Inertia;

use Psr\Http\Message\ResponseInterface;

class Inertia
{
    private static string $version = '1';
    private static array  $shared  = [];

    /**
     * Share data with all Inertia responses
     */
    public static function share(array $props): void
    {
        self::$shared = array_merge(self::$shared, $props);
    }

    /**
     * Render a page
     */
    public static function render(
        ResponseInterface $response,
        string $component,
        array $props = []
    ): ResponseInterface {
        $page = [
            'component' => $component,
            'props'     => array_merge(self::$shared, $props),
            'url'       => $_SERVER['REQUEST_URI'] ?? '/',
            'version'   => self::$version,
        ];

        if (!empty($_SERVER['HTTP_X_INERTIA'])) {
            $json = json_encode($page, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $response->getBody()->write($json);

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('X-Inertia', 'true')
                ->withHeader('Vary', 'X-Inertia');
        }

        // Full page load — render the root HTML template
        $pageJson = htmlspecialchars(
            json_encode($page, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ENT_QUOTES,
            'UTF-8'
        );

        ob_start();
        include config('settings.views_path') . '/app.php';
        $html = (string) ob_get_clean();

        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Redirect after a form submission
     */
    public static function redirect(ResponseInterface $response, string $url): ResponseInterface
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $status = in_array($method, ['PUT', 'PATCH', 'DELETE'], true) ? 303 : 302;

        return $response->withHeader('Location', $url)->withStatus($status);
    }
}
