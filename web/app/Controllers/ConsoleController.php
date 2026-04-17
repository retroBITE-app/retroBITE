<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Inertia\Inertia;
use App\Repositories\GameRepository;
use App\Services\FilesystemService;
use App\Services\GameDataService;
use App\Support\Console;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ConsoleController
{
    public function __construct(
        private GameRepository $games,
        private FilesystemService $filesystem,
        private GameDataService $gameDataService,
    ) {}

    /**
     * List all installed consoles + available (uninstalled) ones for setup.
     */
    public function index(Request $request, Response $response): Response
    {
        $counts = $this->games->consoleCounts();

        $consoles = Console::allInstalled()
            ->map(fn(Console $c) => $c->toCardArray($counts->get($c->key)))
            ->all();

        $available = Console::allAvailable()
            ->map(fn(Console $c) => [
                'key'        => $c->key,
                'name'       => $c->name,
                'uploadDirs' => [['value' => '', 'label' => $c->folder . '/']],
            ])
            ->all();

        return Inertia::render($response, 'Consoles/Index', [
            'consoles'  => $consoles,
            'available' => $available,
        ]);
    }

    /**
     * Show games for a console.
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $console = Console::tryFrom(Arr::get($args, 'console'));

        if (!$console) {
            return $response->withStatus(404);
        }

        $extensions = [
            'files' => $console->fileExtensions,
            'bios'  => $console->biosExtensions,
        ];

        $type       = Arr::get($request->getQueryParams(), 'type', 'files');
        $filterExts = $type === 'all' ? [] : Arr::get($extensions, $type, []);
        $gameType   = $type === 'all' ? null : $type;

        $games = $this->games->allForConsole($console->key, $filterExts, $gameType);

        return Inertia::render($response, 'Consoles/Show', [
            'console'    => $console->key,
            'meta'       => config("consoles.{$console->key}"),
            'games'      => $this->gameDataService->enrichGames($games),
            'extensions' => $extensions,
            'type'       => $type,
            'uploadDirs' => $console->uploadDirs(),
        ]);
    }

    /**
     * Scan the filesystem for games and update DB metadata.
     */
    public function scan(Request $request, Response $response, array $args): Response
    {
        $console = Console::tryFrom(Arr::get($args, 'console'));

        if (!$console) {
            return $response->withStatus(404);
        }

        foreach ($this->filesystem->scanConsoleDir($console) as $file) {
            $this->games->scanUpsert($console->key, $file);
        }

        return Inertia::redirect($response, '/consoles/' . $console->key);
    }

    /**
     * Receive a single chunk of a file upload.
     */
    public function uploadChunk(Request $request, Response $response, array $args): Response
    {
        $this->filesystem->purgeAbandonedUploads();

        $console = Console::tryFrom(Arr::get($args, 'console'));
        if (!$console) {
            return $this->jsonError($response, 'Unknown console', 404);
        }

        $body = $request->getParsedBody() ?? [];

        $uploadId    = (string) Arr::get($body, 'upload_id', '');
        $filename    = (string) Arr::get($body, 'filename', '');
        $fileSize    = (int)    Arr::get($body, 'file_size', -1);
        $subfolder   = (string) Arr::get($body, 'subfolder', '');
        $chunkIndex  = (int)    Arr::get($body, 'chunk_index', -1);
        $totalChunks = (int)    Arr::get($body, 'total_chunks', -1);

        // Validate upload_id (UUID v4 — goes into filesystem path)
        if (!preg_match('/^[0-9a-f\-]{36}$/', $uploadId)) {
            return $this->jsonError($response, 'Invalid upload_id', 422);
        }

        // Sanitise filename and validate extension
        $filename = basename($filename);
        if ($filename === '' || $filename === '.') {
            return $this->jsonError($response, 'Invalid filename', 422);
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!$console->hasExtension($ext)) {
            return $this->jsonError($response, 'File type not allowed for this console', 422);
        }

        // Validate subfolder (alphanumeric + dash + underscore only — goes into filesystem path)
        if ($subfolder !== '' && !preg_match('/^[a-zA-Z0-9_\-]+$/', $subfolder)) {
            return $this->jsonError($response, 'Invalid subfolder', 422);
        }

        // Validate numeric fields
        if ($fileSize < 0 || $chunkIndex < 0 || $totalChunks < 1 || $chunkIndex >= $totalChunks) {
            return $this->jsonError($response, 'Invalid chunk metadata', 422);
        }

        // Get uploaded chunk
        $files = $request->getUploadedFiles();
        if (!Arr::has($files, 'chunk')) {
            return $this->jsonError($response, 'Missing chunk', 422);
        }

        $this->filesystem->writeChunk($uploadId, $chunkIndex, Arr::get($files, 'chunk'));

        // Intermediate chunk — just acknowledge
        if ($chunkIndex < $totalChunks - 1) {
            $response->getBody()->write(json_encode(['status' => 'received', 'chunk' => $chunkIndex]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        // Final chunk — reassemble and register in DB
        $destPath = $this->filesystem->resolvePath($console, $filename, $subfolder);
        $this->filesystem->assembleFile($uploadId, $totalChunks, $destPath);
        $md5 = md5_file($destPath) ?: null;
        $this->games->upsert($console->key, $filename, $destPath, $fileSize, $md5);

        $response->getBody()->write(json_encode(['status' => 'complete']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Create one or more directories for a console on disk.
     */
    public function mkdir(Request $request, Response $response, array $args): Response
    {
        $console = Console::tryFrom(Arr::get($args, 'console'));

        if (!$console) {
            return $this->jsonError($response, 'Unknown console', 404);
        }

        $body       = $request->getParsedBody() ?? [];
        $subfolders = (array) Arr::get($body, 'subfolders', []);

        foreach ($subfolders as $sub) {
            $sub = (string) $sub;

            if ($sub !== '' && !preg_match('/^[a-zA-Z0-9_\-]+$/', $sub)) {
                return $this->jsonError($response, 'Invalid subfolder: ' . $sub, 422);
            }

            $this->filesystem->createDir($console, $sub);
        }

        $response->getBody()->write(json_encode(['status' => 'ok']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function jsonError(Response $response, string $message, int $status): Response
    {
        $response->getBody()->write(json_encode(['error' => $message]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
