<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Inertia\Inertia;
use App\Support\Csrf;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Owns the PHP session: starts it with hardened cookie flags and publishes the CSRF
 * token. The flags live here because the php:8.4-fpm-alpine image activates no
 * php.ini of ours, so PHP's laxer compiled defaults would otherwise apply.
 */
final class SessionMiddleware
{
    private const COOKIE_PATH = '/';
    private const SAME_SITE   = 'Lax';

    public function __invoke(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->start($request);

        Inertia::share(['csrfToken' => Csrf::token()]);

        return $this->withTokenCookie($handler->handle($request), $request);
    }

    /**
     * Start the session once per request with hardened cookie parameters.
     */
    private function start(ServerRequestInterface $request): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        // Refuse a session id the server never issued, closing session fixation.
        ini_set('session.use_strict_mode', '1');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => self::COOKIE_PATH,
            'httponly' => true,
            'secure'   => $this->isSecure($request),
            'samesite' => self::SAME_SITE,
        ]);

        session_start();
    }

    /**
     * Publish the CSRF token as a non-HttpOnly cookie so axios — and therefore every
     * Inertia request — echoes it back automatically.
     */
    private function withTokenCookie(
        ResponseInterface $response,
        ServerRequestInterface $request,
    ): ResponseInterface {
        $cookie = sprintf(
            '%s=%s; Path=%s; SameSite=%s%s',
            Csrf::COOKIE_NAME,
            Csrf::token(),
            self::COOKIE_PATH,
            self::SAME_SITE,
            $this->isSecure($request) ? '; Secure' : '',
        );

        return $response->withAddedHeader('Set-Cookie', $cookie);
    }

    /**
     * Was this request served over TLS? Read from the URI only — with no trusted-proxy
     * config, guessing would risk a Secure cookie the browser never sends back.
     */
    private function isSecure(ServerRequestInterface $request): bool
    {
        return $request->getUri()->getScheme() === 'https';
    }
}
