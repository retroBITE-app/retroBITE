<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Inertia\Inertia;
use App\Repositories\GameRepository;
use App\Support\Console;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DashboardController
{
    public function __construct(
        private GameRepository $games,
    ) {}

    public function index(Request $_request, Response $response): Response
    {
        $counts = $this->games->consoleCounts();

        $installed = Console::allInstalled();

        $consoles = $installed
            ->map(fn(Console $c) => $c->toCardArray($counts->get($c->key)))
            ->sortByDesc('gameCount')
            ->take(6)
            ->values()
            ->all();

        $shares = $installed
            ->map(fn(Console $c) => $c->toShareArray())
            ->prepend(['key' => '__root__', 'name' => 'All Games', 'folder' => 'games', 'icon' => null])
            ->all();

        return Inertia::render($response, 'Dashboard', [
            'consoles' => $consoles,
            'network'  => [
                'hostIp'   => config('settings.network.host_ip'),
                'username' => config('settings.network.username'),
                'shares'   => $shares,
            ],
        ]);
    }
}
