<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Inertia\Inertia;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class AuthMiddleware
{
    public function __invoke(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $userId = Arr::get($_SESSION, 'user_id', null);
        if (empty($userId)) {
            return (new Response())->withHeader('Location', '/login')->withStatus(302);
        }

        Inertia::share(['auth' => ['user' => Arr::get($_SESSION, 'username', null)]]);

        return $handler->handle($request);
    }
}
