<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Inertia\Inertia;
use App\Models\Game;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\FilesystemService;
use App\Services\GameDataService;

class GameController
{
    private static array $KNOWN_CONSOLES = [];
    private FilesystemService $filesystemService;
    private GameDataService $gameDataService;

    public function __construct(FilesystemService $filesystemService, GameDataService $gameDataService)
    {
        self::$KNOWN_CONSOLES = array_keys(config('consoles'));
        $this->filesystemService = $filesystemService;
        $this->gameDataService = $gameDataService;
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $console  = Arr::get($args, 'console');
        $fileName = Arr::get($args, 'game');

        if (!in_array($console, self::$KNOWN_CONSOLES, true)) {
            return $response->withStatus(404);
        }

        $game = Game::find("{$console}:{$fileName}");

        if ($game === null) {
            return $response->withStatus(404);
        }

        return Inertia::render($response, 'Games/Show', [
            'console' => $console,
            'meta'    => config("consoles.{$console}"),
            'game'    => $this->gameDataService->enrichGame($game),
        ]);
    }
}
