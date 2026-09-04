<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Csrf;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Rejects state-changing requests that do not present the session's CSRF token.
 * Mutating endpoints are authenticated by the session cookie alone, so without
 * this a third-party page could drive them with the visitor's ambient session.
 */
final class CsrfMiddleware
{
    /** Methods that must not change state, and so need no token. */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    private const STATUS_MISMATCH = 419;

    /**
     * Let the request through only if it presents the session token.
     */
    public function __invoke(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->isSafe($request)) {
            return $handler->handle($request);
        }

        if (Csrf::matches(Csrf::fromRequest($request))) {
            return $handler->handle($request);
        }

        logger()->warning('Rejected request with missing or stale CSRF token', [
            'method' => $request->getMethod(),
            'path'   => $request->getUri()->getPath(),
        ]);

        return $this->mismatch();
    }

    /**
     * Is this a read-only method?
     */
    private function isSafe(ServerRequestInterface $request): bool
    {
        return in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true);
    }

    /**
     * 419 with a stable machine-readable code, meaning "reload for a fresh token".
     */
    private function mismatch(): ResponseInterface
    {
        $response = new Response(self::STATUS_MISMATCH);
        $response->getBody()->write(json_encode(
            ['error' => 'csrf_token_mismatch'],
            JSON_THROW_ON_ERROR,
        ));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
