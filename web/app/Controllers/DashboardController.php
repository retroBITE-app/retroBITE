<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Inertia\Inertia;
use App\Models\Game;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DashboardController
{
    public function index(Request $request, Response $response): Response
    {
        $counts = Game::selectRaw("
                console,
                SUM(CASE WHEN file_path NOT LIKE '%/BIOS/%' THEN 1 ELSE 0 END) as game_count,
                SUM(CASE WHEN file_path LIKE '%/BIOS/%' THEN 1 ELSE 0 END) as bios_count
            ")
            ->groupBy('console')
            ->get()
            ->keyBy('console');

        $gamesPath = config('settings.games_path');

        $consoles = Collection::make(config('consoles'))
            ->filter(fn(array $meta) => is_dir($gamesPath . '/' . Arr::get($meta, 'folder')))
            ->map(fn(array $meta, string $key) => [
                'key'       => $key,
                'name'      => $meta['name'],
                'icon'      => $meta['icon'],
                'gameCount' => (int) ($counts->get($key)?->game_count ?? 0),
                'biosCount' => (int) ($counts->get($key)?->bios_count ?? 0),
            ])
            ->sortByDesc('gameCount')
            ->take(6)
            ->values();

        return Inertia::render($response, 'Dashboard', [
            'consoles' => $consoles,
            'network'   => [
                'hostIp' => config('settings.network.host_ip'),
                'username' => config('settings.network.username'),
            ]
        ]);
    }
}
