<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Http\ApiResponse;
use App\Inertia\Inertia;
use App\Services\SidebarService;
use App\Support\Session;
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
    public function __construct(
        private SidebarService $sidebar,
    ) {}

    /**
     * Gate the request, sharing what the layout needs when it passes.
     */
    public function __invoke(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!Session::isAuthenticated()) {
            return $this->challenge($request);
        }

        Inertia::share([
            'auth' => ['user' => Session::username()],
            // A closure, so the sidebar's queries only run when a page actually
            // renders — never on the JSON endpoints, and not on a partial reload
            // that did not ask for it.
            'sidebar' => fn(): array => $this->sidebar->payload(),
        ]);

        return $handler->handle($request);
    }

    /**
     * Send the caller to the login page in whichever form it understands.
     */
    private function challenge(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getHeaderLine('X-Inertia') !== '') {
            return Inertia::location(new Response(), route('login'));
        }

        if ($this->wantsJson($request)) {
            return ApiResponse::error(new Response(), 'Not signed in', 401, 'unauthenticated');
        }

        return (new Response(302))->withHeader('Location', route('login'));
    }

    /**
     * Did the caller ask for JSON rather than a page?
     */
    private function wantsJson(ServerRequestInterface $request): bool
    {
        return str_contains($request->getHeaderLine('Accept'), 'application/json');
    }
}
