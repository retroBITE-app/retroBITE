<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Inertia\Inertia;
use App\Repositories\GameRepository;
use App\Services\FilesystemService;
use App\Services\GameDataService;
use App\Support\Console;
use App\Support\PathRules;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Throwable;

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
                'key'  => $c->key,
                'name' => $c->name,
            ])
            ->all();

        return Inertia::render($response, 'Consoles/Index', [
            'consoles'  => $consoles,
            'available' => $available,
        ]);
    }

    /**
     * Show games for a console, filtered by a disk-derived subfolder selection.
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $console = Console::tryFrom(Arr::get($args, 'console'));

        if (!$console) {
            return $response->withStatus(404);
        }

        $folderParam = (string) Arr::get($request->getQueryParams(), 'folder', '');
        $folderList  = $folderParam === ''
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $folderParam))));

        $subfolders = $this->filesystem->listSubfolders($console);
        $games      = $this->games->allForConsoleFolders($console, $folderList);

        // Build filter pills with counts derived from all games for the console.
        $all      = $this->games->allForConsoleFolders($console, []);
        $basePath = '/' . $console->folder . '/';

        $folders = [['value' => '', 'label' => 'All', 'count' => $all->count()]];
        $folders[] = [
            'value' => 'root',
            'label' => $console->folder,
            'count' => $all
                ->filter(fn($g) => !preg_match('~' . preg_quote($basePath, '~') . '[^/]+/~', (string) $g->file_path))
                ->count(),
        ];
        foreach ($subfolders as $sub) {
            $folders[] = [
                'value' => $sub,
                'label' => $sub,
                'count' => $all
                    ->filter(fn($g) => str_contains((string) $g->file_path, $basePath . $sub . '/'))
                    ->count(),
            ];
        }

        // Upload-dir picker: root + every subfolder discovered on disk.
        $uploadDirs = [['value' => '', 'label' => $console->folder . '/']];
        foreach ($subfolders as $sub) {
            $uploadDirs[] = ['value' => $sub, 'label' => $console->folder . '/' . $sub . '/'];
        }

        $extensions = Arr::collapse([$console->fileExtensions, $console->biosExtensions]);

        return Inertia::render($response, 'Consoles/Show', [
            'console'    => $console->key,
            'meta'       => config("consoles.{$console->key}"),
            'games'      => $this->gameDataService->enrichGames($games),
            'extensions' => $extensions,
            'folders'    => $folders,
            'folder'     => $folderParam,
            'uploadDirs' => $uploadDirs,
        ]);
    }

    /**
     * Scan the filesystem for games and update DB metadata, then drop rows whose
     * file is no longer on disk so the database matches the directory.
     */
    public function scan(Request $request, Response $response, array $args): Response
    {
        $console = Console::tryFrom(Arr::get($args, 'console'));

        if (!$console) {
            return $response->withStatus(404);
        }

        $mounted = is_dir($console->path());

        foreach ($this->filesystem->scanConsoleDir($console) as $file) {
            $this->games->scanUpsert($console->key, $file);
        }

        if ($mounted) {
            $pruned = $this->games->pruneMissing($console);

            if ($pruned > 0) {
                logger()->info('Pruned missing games', [
                    'console' => $console->key,
                    'removed' => $pruned,
                ]);
            }
        }

        return Inertia::redirect($response, '/consoles/' . $console->key);
    }

    /**
     * Receive a single chunk of a file upload, assembling the file on the last one.
     */
    public function uploadChunk(Request $request, Response $response, array $args): Response
    {
        $this->filesystem->purgeAbandonedUploads();

        $console = Console::tryFrom(Arr::get($args, 'console'));
        if (!$console) {
            return $this->jsonError($response, 'Unknown console', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);

        $uploadId    = (string) Arr::get($body, 'upload_id', '');
        $filename    = basename((string) Arr::get($body, 'filename', ''));
        $fileSize    = (int)    Arr::get($body, 'file_size', -1);
        $subfolder   = PathRules::normalizeSubfolder((string) Arr::get($body, 'subfolder', ''));
        $chunkIndex  = (int)    Arr::get($body, 'chunk_index', -1);
        $totalChunks = (int)    Arr::get($body, 'total_chunks', -1);

        if ($error = $this->validateChunk($console, $uploadId, $filename, $subfolder, $fileSize, $chunkIndex, $totalChunks)) {
            return $this->jsonError($response, $error, 422);
        }

        $chunk = Arr::get($request->getUploadedFiles(), 'chunk');
        if ($chunk === null) {
            return $this->jsonError($response, 'Missing chunk', 422);
        }

        try {
            $this->filesystem->writeChunk($uploadId, $chunkIndex, $chunk);

            if ($chunkIndex < $totalChunks - 1) {
                return $this->json($response, ['status' => 'received', 'chunk' => $chunkIndex]);
            }

            $this->registerUpload($console, $uploadId, $filename, $subfolder, $fileSize, $totalChunks);
        } catch (Throwable $e) {
            logger()->error('Upload failed', [
                'console'   => $console->key,
                'upload_id' => $uploadId,
                'message'   => $e->getMessage(),
            ]);

            return $this->jsonError($response, 'Upload failed', 500);
        }

        return $this->json($response, ['status' => 'complete']);
    }

    /**
     * Reason the chunk metadata is unacceptable, or null when it is fine.
     */
    private function validateChunk(
        Console $console,
        string $uploadId,
        string $filename,
        string $subfolder,
        int $fileSize,
        int $chunkIndex,
        int $totalChunks,
    ): ?string {
        if (!PathRules::isUploadId($uploadId)) {
            return 'Invalid upload_id';
        }

        if ($filename === '' || $filename === '.' || $filename === '..') {
            return 'Invalid filename';
        }

        if (!$console->hasExtension(strtolower(pathinfo($filename, PATHINFO_EXTENSION)))) {
            return 'File type not allowed for this console';
        }

        if (!PathRules::isSubfolderOrRoot($subfolder)) {
            return 'Invalid subfolder';
        }

        if ($chunkIndex < 0 || $totalChunks < 1 || $chunkIndex >= $totalChunks) {
            return 'Invalid chunk metadata';
        }

        if ($fileSize < 0) {
            return 'Invalid file size';
        }

        // nginx caps a single chunk, not the assembled file, so the total is capped here.
        if ($fileSize > (int) config('settings.upload_max_bytes')) {
            return 'File exceeds the maximum upload size';
        }

        return null;
    }

    /**
     * Assemble the staged chunks and record the finished file, verifying that what
     * landed on disk is the size the client declared.
     */
    private function registerUpload(
        Console $console,
        string $uploadId,
        string $filename,
        string $subfolder,
        int $declaredSize,
        int $totalChunks,
    ): void {
        $destPath = $this->filesystem->resolvePath($console, $filename, $subfolder);

        $this->filesystem->assembleFile($uploadId, $totalChunks, $destPath);

        $actualSize = filesize($destPath);

        if ($actualSize === false || $actualSize !== $declaredSize) {
            $this->filesystem->deleteFile($console, $destPath);

            throw new RuntimeException(
                "Assembled size {$actualSize} does not match the declared {$declaredSize}"
            );
        }

        $this->games->upsert(
            $console->key,
            $filename,
            $destPath,
            $actualSize,
            md5_file($destPath) ?: null,
        );
    }

    /**
     * Recursively delete a subfolder of a console — disk + DB rows.
     */
    public function deleteFolder(Request $request, Response $response, array $args): Response
    {
        $console = Console::tryFrom(Arr::get($args, 'console'));
        if (!$console) {
            return $this->jsonError($response, 'Unknown console', 404);
        }

        $folder = PathRules::normalizeSubfolder((string) Arr::get($args, 'folder', ''));

        if ($folder === 'root' || !PathRules::isSubfolder($folder)) {
            return $this->jsonError($response, 'Invalid folder', 422);
        }

        // Disk first: dropping the rows first left them gone when the rmdir failed.
        if (!$this->filesystem->deleteDir($console, $folder)) {
            return $this->jsonError($response, 'Folder not found or could not be deleted', 500);
        }

        return $this->json($response, [
            'status'        => 'ok',
            'games_removed' => $this->games->deleteByFolder($console, $folder),
        ]);
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

        $targets = [];

        foreach ($subfolders as $sub) {
            // '' is the console root — creating it is how a console gets installed.
            $sub = PathRules::normalizeSubfolder((string) $sub);

            if (!PathRules::isSubfolderOrRoot($sub)) {
                return $this->jsonError($response, 'Invalid subfolder: ' . $sub, 422);
            }

            $targets[] = $sub;
        }

        try {
            foreach ($targets as $sub) {
                $this->filesystem->createDir($console, $sub);
            }
        } catch (Throwable $e) {
            logger()->error('Could not create console directory', [
                'console' => $console->key,
                'message' => $e->getMessage(),
            ]);

            return $this->jsonError($response, 'Could not create the directory', 500);
        }

        return $this->json($response, ['status' => 'ok']);
    }

    private function json(Response $response, array $body, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function jsonError(Response $response, string $message, int $status): Response
    {
        return $this->json($response, ['error' => $message], $status);
    }
}
