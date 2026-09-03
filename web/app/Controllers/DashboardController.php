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
    /** Consoles shown as cards before the list is truncated. */
    private const CARD_LIMIT = 6;

    /** Sentinel key for the share entry covering the whole library. */
    private const ALL_GAMES_KEY = '__all__';

    public function __construct(
        private GameRepository $games,
    ) {}

    /**
     * Landing page: the busiest consoles plus the network share list.
     */
    public function index(Request $request, Response $response): Response
    {
        $counts    = $this->games->consoleCounts();
        $installed = Console::allInstalled();

        return Inertia::render($request, $response, 'Dashboard', [
            'consoles' => $installed
                ->map(fn(Console $c) => $c->toCardArray($counts->get($c->key)))
                ->sortByDesc('game_count')
                ->take(self::CARD_LIMIT)
                ->values()
                ->all(),
            'network'  => [
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
