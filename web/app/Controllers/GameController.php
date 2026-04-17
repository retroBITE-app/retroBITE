<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Inertia\Inertia;
use App\Models\Game;
use App\Services\GameDataService;
use App\Support\Console;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class GameController
{
    public function __construct(
        private GameDataService $gameDataService,
    ) {}

    public function show(Request $_request, Response $response, array $args): Response
    {
        $console = Console::tryFrom(Arr::get($args, 'console'));

        if (!$console) {
            return $response->withStatus(404);
        }

        $game = Game::find($console->key . ':' . Arr::get($args, 'game'));

        if ($game === null) {
            return $response->withStatus(404);
        }

        return Inertia::render($response, 'Games/Show', [
            'console' => $console->key,
            'meta'    => config("consoles.{$console->key}"),
            'game'    => $this->gameDataService->enrichGame($game),
        ]);
    }
}
