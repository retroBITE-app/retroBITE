<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Enums\FolderScope;
use App\Enums\ResponseStatus;
use App\Exceptions\UploadException;
use App\Exceptions\ValidationException;
use App\Http\ApiResponse;
use App\Http\Input;
use App\Http\RouteResolver;
use App\Inertia\Inertia;
use App\Repositories\GameRepository;
use App\Services\FilesystemService;
use App\Services\GameDataService;
use App\Support\Console;
use App\Support\PathRules;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

class ConsoleController
{
    public function __construct(
        private RouteResolver $resolve,
        private GameRepository $games,
        private FilesystemService $filesystem,
        private GameDataService $gameDataService,
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
     * Show games for a console, filtered by a disk-derived subfolder selection.
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $console     = $this->resolve->console($args);
        $folderParam = Input::query($request)->string('folder');
        $folders     = $this->requestedFolders($request);
        $subfolders  = $this->filesystem->listSubfolders($console);

        return Inertia::render($request, $response, 'Consoles/Show', [
            'console'     => $console->key,
            'meta'        => $console->toMetaArray(),
            'games'       => $this->gameDataService->enrichGames(
                $this->games->allForConsoleFolders($console, $folders)
            ),
            'extensions'  => $console->allExtensions(),
            'folders'     => $this->games->folderCounts($console, $subfolders),
            'folder'      => $folderParam,
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

        return Inertia::redirect($response, '/consoles/' . $console->key, $request->getMethod());
    }

    /**
     * Receive a single chunk of a file upload, assembling the file on the last one.
     */
    public function uploadChunk(Request $request, Response $response, array $args): Response
    {
        $this->filesystem->purgeAbandonedUploads();

        $console = $this->resolve->console($args);
        $input   = Input::body($request);

        $uploadId    = $input->string('upload_id');
        $filename    = basename($input->string('filename'));
        $fileSize    = $input->integer('file_size', -1);
        $subfolder   = PathRules::normalizeSubfolder($input->string('subfolder'));
        $chunkIndex  = $input->integer('chunk_index', -1);
        $totalChunks = $input->integer('total_chunks', -1);

        $this->assertChunkAcceptable($console, $uploadId, $filename, $subfolder, $fileSize, $chunkIndex, $totalChunks);

        $chunk = Arr::get($request->getUploadedFiles(), 'chunk');

        if (!$chunk instanceof UploadedFileInterface) {
            throw ValidationException::fields(['chunk' => 'Required']);
        }

        $this->filesystem->writeChunk($uploadId, $chunkIndex, $chunk);

        if ($chunkIndex < $totalChunks - 1) {
            return ApiResponse::status($response, ResponseStatus::Received, ['chunk' => $chunkIndex]);
        }

        $this->registerUpload($console, $uploadId, $filename, $subfolder, $fileSize, $totalChunks);

        return ApiResponse::status($response, ResponseStatus::Complete);
    }

    /**
     * Recursively delete a subfolder of a console — disk first, then its DB rows.
     */
    public function deleteFolder(Request $request, Response $response, array $args): Response
    {
        $console = $this->resolve->console($args);
        $folder  = PathRules::normalizeSubfolder(Input::args($args)->string('folder'));

        if ($folder === 'root' || !PathRules::isSubfolder($folder)) {
            throw ValidationException::because('Invalid folder');
        }

        // Disk first: dropping the rows first left them gone when the rmdir failed.
        if (!$this->filesystem->deleteDir($console, $folder)) {
            throw ValidationException::because('Folder not found or could not be deleted');
        }

        return ApiResponse::status($response, ResponseStatus::Ok, [
            'games_removed' => $this->games->deleteByFolder($console, $folder),
        ]);
    }

    /**
     * Create one or more directories for a console on disk.
     */
    public function mkdir(Request $request, Response $response, array $args): Response
    {
        $console = $this->resolve->console($args);

        // '' is the console root — creating it is how a console gets installed.
        $targets = Arr::map(
            Input::body($request)->list('subfolders'),
            fn(mixed $sub): string => PathRules::normalizeSubfolder((string) $sub),
        );

        foreach ($targets as $sub) {
            if (!PathRules::isSubfolderOrRoot($sub)) {
                throw ValidationException::because('Invalid subfolder: ' . $sub);
            }
        }

        foreach ($targets as $sub) {
            $this->filesystem->createDir($console, $sub);
        }

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

    /**
     * Reject chunk metadata we will not act on.
     *
     * @throws ValidationException
     */
    private function assertChunkAcceptable(
        Console $console,
        string $uploadId,
        string $filename,
        string $subfolder,
        int $fileSize,
        int $chunkIndex,
        int $totalChunks,
    ): void {
        if (!PathRules::isUploadId($uploadId)) {
            throw ValidationException::fields(['upload_id' => 'Invalid']);
        }

        if ($filename === '' || $filename === '.' || $filename === '..') {
            throw ValidationException::fields(['filename' => 'Invalid']);
        }

        if (!$console->hasExtension(strtolower(pathinfo($filename, PATHINFO_EXTENSION)))) {
            throw ValidationException::because('File type not allowed for this console');
        }

        if (!PathRules::isSubfolderOrRoot($subfolder)) {
            throw ValidationException::fields(['subfolder' => 'Invalid']);
        }

        if ($chunkIndex < 0 || $totalChunks < 1 || $chunkIndex >= $totalChunks) {
            throw ValidationException::because('Invalid chunk metadata');
        }

        if ($fileSize < 0) {
            throw ValidationException::fields(['file_size' => 'Invalid']);
        }

        // nginx caps a single chunk, not the assembled file, so the total is capped here.
        if ($fileSize > (int) config('settings.upload_max_bytes')) {
            throw ValidationException::because('File exceeds the maximum upload size');
        }
    }

    /**
     * Assemble the staged chunks and record the finished file, verifying that what
     * landed on disk is the size the client declared.
     *
     * @throws UploadException
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

            throw UploadException::sizeMismatch($declaredSize, (int) $actualSize);
        }

        $this->games->upsert(
            $console->key,
            $filename,
            $destPath,
            $actualSize,
            md5_file($destPath) ?: null,
        );
    }
}
