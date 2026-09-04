<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Enums\ResponseStatus;
use App\Exceptions\ValidationException;
use App\Http\ApiResponse;
use App\Http\Input;
use App\Http\RouteResolver;
use App\Inertia\Inertia;
use App\Services\FilesystemService;
use App\Services\GameDataService;
use App\Services\GameIdentificationService;
use App\Services\GameMoveService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class GameController
{
    public function __construct(
        private RouteResolver $resolve,
        private FilesystemService $filesystem,
        private GameDataService $gameData,
        private GameIdentificationService $identification,
        private GameMoveService $moves,
    ) {}

    /**
     * Game detail page, including the folders it may be moved into.
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $console = $this->resolve->console($args);
        $game    = $this->resolve->game($console, $args);
        $folder  = $this->filesystem->relativeFolder($console, $game->file_path);

        return Inertia::render($request, $response, 'Games/Show', [
            'console'      => $console->key,
            'meta'         => $console->toMetaArray(),
            'game'         => $this->gameData->enrichGame($game),
            'folder'       => $folder,
            'folder_label' => $console->folderLabel($folder),
            'folders'      => $console->folderOptions($this->filesystem->listSubfolders($console)),
        ]);
    }

    /**
     * Candidate provider matches for a game, for the user to pick from.
     */
    public function identify(Request $request, Response $response, array $args): Response
    {
        $console = $this->resolve->console($args);
        $game    = $this->resolve->game($console, $args);

        return ApiResponse::json($response, $this->identification->candidatesFor(
            $console,
            $game,
            Input::query($request)->string('search'),
        ));
    }

    /**
     * Persist the metadata for a user-chosen provider match.
     */
    public function assignMetadata(Request $request, Response $response, array $args): Response
    {
        $console  = $this->resolve->console($args);
        $game     = $this->resolve->game($console, $args);
        $metadata = $this->identification->assign($game, Input::body($request)->integer('provider_id'));

        return ApiResponse::status($response, ResponseStatus::Ok, [
            'metadata' => [
                'title'       => $metadata->title,
                'provider_id' => $metadata->provider_id,
                'fetched_at'  => $metadata->fetched_at,
            ],
        ]);
    }

    /**
     * Move a game file into another subfolder of the same console.
     */
    public function move(Request $request, Response $response, array $args): Response
    {
        $console = $this->resolve->console($args);
        $game    = $this->resolve->game($console, $args);
        $input   = Input::body($request);

        if (!$input->has('subfolder')) {
            throw ValidationException::fields(['subfolder' => 'Required']);
        }

        $subfolder = trim($input->string('subfolder'), '/');

        $this->moves->move($console, $game, $subfolder);

        return ApiResponse::status($response, ResponseStatus::Ok, ['folder' => $subfolder]);
    }

    /**
     * Permanently delete a game: unlinks the file on disk and removes the game
     * row. Not reversible.
     *
     * The game_metadata row is keyed by md5 and left in place, so re-adding the
     * same file restores its identification without another provider lookup.
     */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $console = $this->resolve->console($args);
        $game    = $this->resolve->game($console, $args);

        $this->filesystem->deleteFile($console, (string) $game->file_path);
        $game->delete();

        return ApiResponse::status($response);
    }
}
