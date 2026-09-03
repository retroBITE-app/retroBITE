<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Inertia\Inertia;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Gates routes needing a logged-in user, and shares that user with the frontend.
 * An unauthenticated caller is answered in the shape it can act on — answering
 * every case with a bare 302 made an expired session read as "Save failed".
 */
final class AuthMiddleware
{
    private const LOGIN_PATH = '/login';

    public function __invoke(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->isAuthenticated()) {
            return $this->challenge($request);
        }

        Inertia::share(['auth' => ['user' => Arr::get($_SESSION, 'username')]]);

        return $handler->handle($request);
    }

    /**
     * Is a user id present on the session?
     */
    private function isAuthenticated(): bool
    {
        return Arr::get($_SESSION, 'user_id') !== null;
    }

    /**
     * Send the caller to the login page in whichever form it understands.
     */
    private function challenge(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getHeaderLine('X-Inertia') !== '') {
            return (new Response(409))->withHeader('X-Inertia-Location', self::LOGIN_PATH);
        }

        if ($this->wantsJson($request)) {
            $response = new Response(401);
            $response->getBody()->write(json_encode(
                ['error' => 'unauthenticated'],
                JSON_THROW_ON_ERROR,
            ));

            return $response->withHeader('Content-Type', 'application/json');
        }

        return (new Response(302))->withHeader('Location', self::LOGIN_PATH);
    }

    /**
     * Did the caller ask for JSON rather than a page?
     */
    private function wantsJson(ServerRequestInterface $request): bool
    {
        return str_contains($request->getHeaderLine('Accept'), 'application/json');
    }
}
