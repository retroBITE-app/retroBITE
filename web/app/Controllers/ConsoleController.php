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
use App\Services\LibraryScanService;
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
        private LibraryScanService $scanner,
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
     * Uninstall one or more consoles — their directories and every game row under
     * them. Bulk-shaped like install(), so one card's delete and a whole
     * selection share a single endpoint and a single failure shape.
     */
    public function destroy(Request $request, Response $response): Response
    {
        $result = $this->folders->uninstall(Input::body($request)->list('consoles'));

        if ($result['failures'] !== []) {
            return ApiResponse::error(
                $response,
                'Some consoles could not be deleted',
                422,
                'delete_failed',
                $result['failures'],
            );
        }

        return ApiResponse::status($response, ResponseStatus::Ok, [
            'games_removed' => $result['removed'],
        ]);
    }

    /**
     * Show games for a console, filtered by a disk-derived subfolder selection.
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $console    = $this->resolve->console($args);
        $subfolders = $this->filesystem->listSubfolders($console);

        return Inertia::render($request, $response, 'Consoles/Show', [
            'console'      => $console->key,
            'meta'         => $console->toMetaArray(),
            'games'        => $this->gameData->enrichGames(
                $this->games->allForConsoleFolders($console, $this->requestedFolders($request))
            ),
            'extensions'   => $console->allExtensions(),
            'folders'      => $this->games->folderCounts($console, $subfolders),
            'folder'       => Input::query($request)->string('folder'),
            'upload_dirs'  => $console->folderOptions($subfolders),
            'pending_hash' => $this->games->awaitingHashCount($console),
        ]);
    }

    /**
     * Index the console directory and drop rows whose file is no longer on disk,
     * so the database matches the directory.
     *
     * Deliberately does not hash: that is `hash()` below, which the page polls.
     */
    public function scan(Request $request, Response $response, array $args): Response
    {
        $console = $this->resolve->console($args);
        $result  = $this->scanner->index($console);

        if ($result['pruned'] > 0) {
            logger()->info('Pruned missing games', ['console' => $console->key, 'removed' => $result['pruned']]);
        }

        return Inertia::redirect(
            $response,
            route('console', ['console' => $console->key]),
            $request->getMethod(),
        );
    }

    /**
     * Hash one batch of the console's unhashed games and report the backlog.
     *
     * Kept to a time budget so no single request outlives its execution limit;
     * the caller repeats it while `remaining` is above zero.
     */
    public function hash(Request $request, Response $response, array $args): Response
    {
        $console = $this->resolve->console($args);

        return ApiResponse::status($response, ResponseStatus::Ok, $this->scanner->hashPending($console));
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
