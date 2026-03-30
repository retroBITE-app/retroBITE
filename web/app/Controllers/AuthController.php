<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Inertia\Inertia;
use App\Models\User;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController
{
    /**
     * Show the login form
     */
    public function showLogin(Request $request, Response $response): Response
    {
        $error = Arr::get($_SESSION, 'error', null);
        Arr::forget($_SESSION, 'error');

        return Inertia::render($response, 'Auth/Login', [
            'error' => $error,
        ]);
    }

    /**
     * Log the user in by verifying credentials
     */
    public function login(Request $request, Response $response): Response
    {
        $body       = (array) $request->getParsedBody();
        $username   = trim((string) (Arr::get($body, 'username', '')));
        $password   = (string) (Arr::get($body, 'password', ''));
        
        $user       = User::where('username', $username)->first();

        if ($user && password_verify($password, $user->password)) {
            session_regenerate_id(true);
            Arr::set($_SESSION, 'user_id', $user->id);
            Arr::set($_SESSION, 'username', $user->username);

            return Inertia::redirect($response, '/');
        }

        Arr::set($_SESSION, 'error', 'Invalid username or password.');

        return Inertia::redirect($response, '/login');
    }

    /**
     * Log the user out by destroying the session and redirecting to the login page
     */
    public function logout(Request $request, Response $response): Response
    {
        session_destroy();
        return Inertia::redirect($response, '/login');
    }
}
