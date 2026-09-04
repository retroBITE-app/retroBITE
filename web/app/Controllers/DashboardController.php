<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Inertia\Inertia;
use App\Services\DashboardService;
use App\Support\Console;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DashboardController
{
    /** Sentinel key for the share entry covering the whole library. */
    private const ALL_GAMES_KEY = '__all__';

    public function __construct(
        private DashboardService $dashboard,
    ) {}

    /**
     * Landing page: the newest arrivals, the library's figures, what still needs
     * identifying, and the network share list.
     */
    public function index(Request $request, Response $response): Response
    {
        $installed = Console::allInstalled();

        return Inertia::render($request, $response, 'Dashboard', [
            ...$this->dashboard->payload(),
            'network' => [
                'host_ip'  => config('settings.network.host_ip'),
                'username' => config('settings.network.username'),
                'shares'   => $installed
                    ->map(fn(Console $c) => $c->toShareArray())
                    ->prepend($this->allGamesShare())
                    ->all(),
            ],
        ]);
    }

    /**
     * The share entry pointing at the library root rather than one console.
     *
     * @return array{key: string, name: string, folder: string, icon: null}
     */
    private function allGamesShare(): array
    {
        return [
            'key'    => self::ALL_GAMES_KEY,
            'name'   => 'All Games',
            'folder' => basename((string) config('settings.games_path')),
            'icon'   => null,
        ];
    }
}
