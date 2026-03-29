<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Inertia\Inertia;
use App\Repositories\GameRepository;
use App\Services\FilesystemService;
use App\Models\Game;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ConsoleController
{
    private static array $KNOWN_CONSOLES = [];

    public function __construct(
        private GameRepository $games,
        private FilesystemService $filesystem,
    ) {
        self::$KNOWN_CONSOLES = array_keys(config('consoles'));
    }

    /**
     * List all consoles with games
     */
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
                'key'        => $key,
                'name'       => Arr::get($meta, 'name'),
                'icon'       => Arr::get($meta, 'icon'),
                'gameCount'  => (int) ($counts->get($key)?->game_count ?? 0),
                'biosCount'  => (int) ($counts->get($key)?->bios_count ?? 0),
                'uploadDirs' => Collection::make([['value' => '', 'label' => Arr::get($meta, 'folder') . '/']])
                    ->filter(fn() => !is_dir($gamesPath . '/' . Arr::get($meta, 'folder')))
                    ->values(),
            ])
            ->values();

        return Inertia::render($response, 'Consoles/Index', [
            'consoles' => $consoles,
        ]);
    }

    /**
     * Show games for a console
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $console = Arr::get($args, 'console');

        if (!in_array($console, self::$KNOWN_CONSOLES, true)) {
            return $response->withStatus(404);
        }

        $extensions = [
            'files' => config("consoles.{$console}.file_extensions", []),
            'bios'  => config("consoles.{$console}.bios_extensions", []),
        ];

        $type       = Arr::get($request->getQueryParams(), 'type', 'files');
        $filterExts = $type === 'all' ? [] : Arr::get($extensions, $type, []);
        $gameType   = $type === 'all' ? null : $type;

        $games = $this->games->allForConsole($console, $filterExts, $gameType);

        $folder     = config("consoles.{$console}.folder");
        $gamesPath  = config('settings.games_path');
        $subfolders = config("consoles.{$console}.subfolders", []);
        $uploadDirs = Collection::make($subfolders)
            ->map(fn(string $sub) => ['value' => $sub, 'label' => $folder . '/' . $sub . '/'])
            ->prepend(['value' => '', 'label' => $folder . '/'])
            ->filter(fn(array $dir) => !is_dir($gamesPath . '/' . $folder . ($dir['value'] !== '' ? '/' . $dir['value'] : '')))
            ->values();

        return Inertia::render($response, 'Consoles/Show', [
            'console'    => $console,
            'meta'       => config("consoles.{$console}"),
            'games'      => $games,
            'extensions' => $extensions,
            'type'       => $type,
            'uploadDirs' => $uploadDirs,
        ]);
    }

    /**
     * Scan the filesystem for games and update DB metadata
     */
    public function scan(Request $request, Response $response, array $args): Response
    {
        $console = Arr::get($args, 'console');

        if (!in_array($console, self::$KNOWN_CONSOLES, true)) {
            return $response->withStatus(404);
        }

        foreach ($this->filesystem->scanConsoleDir($console) as $file) {
            $this->games->upsert($console, $file->getFilename(), $file->getPathname(), $file->getSize());
        }

        return Inertia::redirect($response, '/consoles/' . $console);
    }

    /**
     * Receive a single chunk of a file upload
     */
    public function uploadChunk(Request $request, Response $response, array $args): Response
    {
        $this->filesystem->purgeAbandonedUploads();

        $console = Arr::get($args, 'console');
        if (!in_array($console, self::$KNOWN_CONSOLES, true)) {
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
        $ext     = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowed = array_merge(
            config("consoles.{$console}.file_extensions", []),
            config("consoles.{$console}.bios_extensions", [])
        );
        if (!in_array($ext, $allowed, true)) {
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
        $this->games->upsert($console, $filename, $destPath, $fileSize);

        $response->getBody()->write(json_encode(['status' => 'complete']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Create one or more directories for a console on disk
     */
    public function mkdir(Request $request, Response $response, array $args): Response
    {
        $console = Arr::get($args, 'console');

        if (!in_array($console, self::$KNOWN_CONSOLES, true)) {
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
