<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Enums\FolderScope;
use App\Enums\ResponseStatus;
use App\Http\ApiResponse;
use App\Http\Input;
use App\Http\RouteResolver;
use App\Inertia\Inertia;
use App\Repositories\GameRepository;
use App\Services\ChunkedUploadService;
use App\Services\ConsoleFolderService;
use App\Services\FilesystemService;
use App\Services\GameDataService;
use App\Support\Console;
use App\Support\PathRules;
use App\Support\UploadChunk;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ConsoleController
{
    public function __construct(
        private RouteResolver $resolve,
        private GameRepository $games,
        private FilesystemService $filesystem,
        private GameDataService $gameData,
        private ChunkedUploadService $uploads,
        private ConsoleFolderService $folders,
    ) {}

    /**
     * List all installed consoles plus the available ones offered for setup.
     */
    public function index(Request $request, Response $response): Response
    {
        $counts = $this->games->consoleCounts();

        return Inertia::render($request, $response, 'Consoles/Index', [
            'consoles'  => Console::allInstalled()
                ->map(fn(Console $c) => $c->toCardArray($counts->get($c->key)))
                ->all(),
            'available' => Console::allAvailable()
                ->map(fn(Console $c) => ['key' => $c->key, 'name' => $c->name])
                ->all(),
        ]);
    }

    /**
     * Install one or more consoles by creating their root folders.
     */
    public function install(Request $request, Response $response): Response
    {
        $failures = $this->folders->install(Input::body($request)->list('consoles'));

        if ($failures !== []) {
            return ApiResponse::error(
                $response,
                'Some consoles could not be installed',
                422,
                'install_failed',
                $failures,
            );
        }

        return ApiResponse::status($response);
    }

    /**
     * Show games for a console, filtered by a disk-derived subfolder selection.
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $console    = $this->resolve->console($args);
        $subfolders = $this->filesystem->listSubfolders($console);

        return Inertia::render($request, $response, 'Consoles/Show', [
            'console'     => $console->key,
            'meta'        => $console->toMetaArray(),
            'games'       => $this->gameData->enrichGames(
                $this->games->allForConsoleFolders($console, $this->requestedFolders($request))
            ),
            'extensions'  => $console->allExtensions(),
            'folders'     => $this->games->folderCounts($console, $subfolders),
            'folder'      => Input::query($request)->string('folder'),
            'upload_dirs' => $console->folderOptions($subfolders),
        ]);
    }

    /**
     * Scan the filesystem for games and update DB metadata, then drop rows whose
     * file is no longer on disk so the database matches the directory.
     */
    public function scan(Request $request, Response $response, array $args): Response
    {
        $console = $this->resolve->console($args);
        $mounted = is_dir($console->path());

        foreach ($this->filesystem->scanConsoleDir($console) as $file) {
            $this->games->scanUpsert($console->key, $file);
        }

        if ($mounted && ($pruned = $this->games->pruneMissing($console)) > 0) {
            logger()->info('Pruned missing games', ['console' => $console->key, 'removed' => $pruned]);
        }

        return Inertia::redirect(
            $response,
            route('console', ['console' => $console->key]),
            $request->getMethod(),
        );
    }

    /**
     * Receive a single chunk of a file upload, assembling the file on the last one.
     */
    public function uploadChunk(Request $request, Response $response, array $args): Response
    {
        $console  = $this->resolve->console($args);
        $complete = $this->uploads->accept($console, UploadChunk::fromRequest($request));

        return $complete
            ? ApiResponse::status($response, ResponseStatus::Complete)
            : ApiResponse::status($response, ResponseStatus::Received);
    }

    /**
     * Recursively delete a subfolder of a console — disk first, then its DB rows.
     */
    public function deleteFolder(Request $request, Response $response, array $args): Response
    {
        $console = $this->resolve->console($args);

        return ApiResponse::status($response, ResponseStatus::Ok, [
            'games_removed' => $this->folders->delete($console, Input::args($args)->string('folder')),
        ]);
    }

    /**
     * Create one or more directories for a console on disk.
     */
    public function mkdir(Request $request, Response $response, array $args): Response
    {
        $this->folders->create(
            $this->resolve->console($args),
            Input::body($request)->list('subfolders'),
        );

        return ApiResponse::status($response);
    }

    /**
     * Folder filter values from the query string, discarding anything that is not
     * the root sentinel or a legal subfolder — an unchecked value reaches a LIKE
     * pattern, where "%" would match the whole library.
     *
     * @return string[]
     */
    private function requestedFolders(Request $request): array
    {
        return Arr::where(
            Input::query($request)->commaSeparated('folder'),
            fn(string $folder): bool => $folder === FolderScope::Root->value
                || PathRules::isSubfolder($folder),
        );
    }
}
