<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Inertia\Inertia;
use App\Models\Game;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\FilesystemService;

class GameController
{
    private static array $KNOWN_CONSOLES = [];
    private FilesystemService $filesystemService;

    public function __construct(FilesystemService $filesystemService)
    {
        self::$KNOWN_CONSOLES = array_keys(config('consoles'));
        $this->filesystemService = $filesystemService;
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

        $region = $this->filesystemService->resolveRegion($fileName) ?? 'unknown';
        $regionMeta = config("regions.{$region}");

        return Inertia::render($response, 'Games/Show', [
            'console'    => $console,
            'meta'       => config("consoles.{$console}"),
            'game'       => $game,
            'region'     => $region,
            'regionMeta' => $regionMeta,
        ]);
    }
}
