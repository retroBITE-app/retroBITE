<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Inertia\Inertia;
use App\Models\User;
use App\Services\LoginThrottleService;
use App\Support\Csrf;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController
{
    public function __construct(
        private LoginThrottleService $throttle,
    ) {}

    /**
     * Show the login form, surfacing and consuming any pending error message.
     */
    public function showLogin(Request $request, Response $response): Response
    {
        $error = Arr::get($_SESSION, 'error');
        Arr::forget($_SESSION, 'error');

        return Inertia::render($response, 'Auth/Login', [
            'error' => $error,
        ]);
    }

    /**
     * Log the user in by verifying credentials, subject to a per-address throttle.
     */
    public function login(Request $request, Response $response): Response
    {
        $ip = $this->clientIp($request);

        if ($this->throttle->isLocked($ip)) {
            return $this->failed($response, 'Too many attempts. Try again later.');
        }

        $body     = (array) $request->getParsedBody();
        $username = trim((string) Arr::get($body, 'username', ''));
        $password = (string) Arr::get($body, 'password', '');

        $user = User::where('username', $username)->first();

        if (!$user || !password_verify($password, $user->password)) {
            $this->throttle->recordFailure($ip);
            logger()->warning('Failed login attempt', ['ip' => $ip, 'username' => $username]);

            return $this->failed($response, 'Invalid username or password.');
        }

        $this->throttle->clear($ip);
        $this->establishSession($user);

        return Inertia::redirect($response, '/');
    }

    /**
     * Log the user out, discarding the session and the cookies that address it.
     */
    public function logout(Request $request, Response $response): Response
    {
        $_SESSION = [];
        session_unset();
        session_destroy();

        return $this->withExpiredCookies(Inertia::redirect($response, '/login'));
    }

    /**
     * Bind the session to a user, rotating the session id and CSRF token so anything
     * captured before the privilege change stops working.
     */
    private function establishSession(User $user): void
    {
        session_regenerate_id(true);

        Arr::set($_SESSION, 'user_id', $user->id);
        Arr::set($_SESSION, 'username', $user->username);

        Csrf::rotate();
    }

    /**
     * Flash a login error and send the user back to the form.
     */
    private function failed(Response $response, string $message): Response
    {
        Arr::set($_SESSION, 'error', $message);

        return Inertia::redirect($response, '/login');
    }

    /**
     * Expire the session and CSRF cookies — session_destroy() leaves both in the
     * browser, which keeps presenting a session id that no longer resolves.
     */
    private function withExpiredCookies(Response $response): Response
    {
        $expired = 'Expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=0; Path=/';

        return $response
            ->withAddedHeader('Set-Cookie', session_name() . '=; ' . $expired . '; HttpOnly; SameSite=Lax')
            ->withAddedHeader('Set-Cookie', Csrf::COOKIE_NAME . '=; ' . $expired . '; SameSite=Lax');
    }

    /**
     * The address to throttle against. REMOTE_ADDR only — honouring X-Forwarded-For
     * without a trusted-proxy config would let a caller choose its own bucket.
     */
    private function clientIp(Request $request): string
    {
        return (string) Arr::get($request->getServerParams(), 'REMOTE_ADDR', 'unknown');
    }
}
