<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HttpFoundation\Response;

/**
 * A file Laravel has decided may be served, handed to nginx to send.
 *
 * PHP checks what it always checked — the row exists, the file is there —
 * and answers with an empty body and X-Accel-Redirect; nginx serves the bytes
 * by sendfile from an internal location (docker/web/nginx*.conf). A PHP worker
 * streaming a four-gigabyte disc, or a console's two thousand files a few at a
 * time, was the one thing between the drive and the browser.
 *
 * Off unless SERVE_WITH_NGINX says the container's nginx is in front: the
 * test suite, and anything run outside the image, still get the file itself.
 */
final class NginxFile
{
    public static function enabled(): bool
    {
        return (bool) config('settings.serve_with_nginx');
    }

    /**
     * @param  string  $location  the internal prefix, e.g. /_serve/games
     * @param  string  $relative  the file's path under that location's root
     * @param  array<string, string>  $headers  sent as they would be with the file
     */
    public static function response(string $location, string $relative, array $headers = []): Response
    {
        // Each part encoded on its own, so a name with a space, an & or a #
        // arrives as itself and a slash still separates folders.
        $encoded = implode('/', array_map(rawurlencode(...), explode('/', ltrim($relative, '/'))));

        return new Response('', 200, $headers + ['X-Accel-Redirect' => rtrim($location, '/').'/'.$encoded]);
    }
}
