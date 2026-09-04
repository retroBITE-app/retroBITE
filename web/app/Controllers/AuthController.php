<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Input;
use App\Inertia\Inertia;
use App\Repositories\GameMetadataRepository;
use App\Repositories\UserRepository;
use App\Services\HostSummaryService;
use App\Services\LoginThrottleService;
use App\Support\Csrf;
use App\Support\Session;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController
{
    public function __construct(
        private LoginThrottleService $throttle,
        private UserRepository $users,
        private GameMetadataRepository $metadata,
        private HostSummaryService $host,
    ) {}

    /**
     * Show the login form, surfacing and consuming any pending error message.
     *
     * The backdrop is a different cached artwork on every visit, and is null on a
     * host with no scraped metadata yet.
     */
    public function showLogin(Request $request, Response $response): Response
    {
        return Inertia::render($request, $response, 'Auth/Login', [
            'error'    => Session::pullError(),
            'backdrop' => $this->metadata->randomBackdropUrl(),
            'stats'    => $this->host->loginSummary(),
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

        $input    = Input::body($request);
        $username = $input->string('username');
        $user     = $this->users->findByUsername($username);

        if (!$user || !password_verify($input->string('password'), $user->password)) {
            $this->throttle->recordFailure($ip);
            logger()->warning('Failed login attempt', ['ip' => $ip, 'username' => $username]);

            return $this->failed($response, 'Invalid username or password.');
        }

        $this->throttle->clear($ip);
        Session::login((int) $user->id, (string) $user->username);

        return Inertia::redirect($response, route('dashboard'), $request->getMethod());
    }

    /**
     * Log the user out, discarding the session and the cookies that address it.
     */
    public function logout(Request $request, Response $response): Response
    {
        Session::destroy();

        return $this->withExpiredCookies(
            Inertia::redirect($response, route('login'), $request->getMethod())
        );
    }

    /**
     * Flash a login error and send the user back to the form.
     */
    private function failed(Response $response, string $message): Response
    {
        Session::flashError($message);

        return Inertia::redirect($response, route('login'), 'POST');
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
