<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Enums\MediaKind;
use App\Enums\ResponseStatus;
use App\Exceptions\ConflictException;
use App\Exceptions\ProviderException;
use App\Exceptions\StorageException;
use App\Exceptions\ValidationException;
use App\Http\ApiResponse;
use App\Http\Input;
use App\Http\RouteResolver;
use App\Inertia\Inertia;
use App\Models\Game;
use App\Repositories\GameMetadataRepository;
use App\Repositories\GameRepository;
use App\Services\FilesystemService;
use App\Services\GameDataService;
use App\Services\MediaCacheService;
use App\Services\ScreenScraperService;
use App\Support\Console;
use App\Support\RomFilename;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

class GameController
{
    public function __construct(
        private RouteResolver $resolve,
        private GameRepository $games,
        private GameMetadataRepository $metadataRepo,
        private FilesystemService $filesystem,
        private GameDataService $gameDataService,
        private ScreenScraperService $screenScraper,
        private MediaCacheService $mediaCache,
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
            'game'         => $this->gameDataService->enrichGame($game),
            'folder'       => $folder,
            'folder_label' => $console->folderLabel($folder),
            'folders'      => $console->folderOptions($this->filesystem->listSubfolders($console)),
        ]);
    }

    /**
     * Trigger a ScreenScraper identification for a game. Returns a candidate list
     * the user picks from via the Identify modal. Never auto-persists.
     */
    public function identify(Request $request, Response $response, array $args): Response
    {
        $console = $this->resolve->console($args);
        $game    = $this->resolve->game($console, $args);

        $this->assertScrapable($console);

        $override   = Input::query($request)->string('search');
        $searchName = $override !== '' ? $override : RomFilename::toSearchName((string) $game->file_name);

        try {
            $md5Match = $override === '' && $game->file_md5
                ? $this->screenScraper->lookupByMd5($console, $game->file_md5)
                : null;

            $candidates = $this->screenScraper->search($console, $searchName);
        } catch (Throwable $e) {
            throw ProviderException::unavailable($e->getMessage());
        }

        return ApiResponse::json($response, [
            'md5_match'  => $md5Match,
            'candidates' => $candidates,
        ]);
    }

    /**
     * Persist the metadata for a user-chosen ScreenScraper match.
     */
    public function assignMetadata(Request $request, Response $response, array $args): Response
    {
        $console = $this->resolve->console($args);
        $game    = $this->resolve->game($console, $args);

        if (!$game->file_md5) {
            throw ValidationException::because('Game must have an md5 before identification');
        }

        $providerId = Input::body($request)->integer('provider_id');

        if ($providerId <= 0) {
            throw ValidationException::fields(['provider_id' => 'Required']);
        }

        $payload  = $this->fetchProviderPayload($providerId);
        $metadata = $this->metadataRepo->upsert(
            $game->file_md5,
            $this->withCachedMedia($payload, $game->file_md5),
        );

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

        $input = Input::body($request);

        if (!$input->has('subfolder')) {
            throw ValidationException::fields(['subfolder' => 'Required']);
        }

        $subfolder = trim($input->string('subfolder'), '/');

        $this->assertMovable($console, $game, $subfolder);

        $from = (string) $game->file_path;
        $to   = $this->filesystem->moveFile($console, $from, $subfolder);

        $this->recordMove($game, $from, $to);

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

    /**
     * Reject a console the provider has no system id for.
     */
    private function assertScrapable(Console $console): void
    {
        if ($console->screenscraperId === null) {
            throw ValidationException::because('Console not mapped to ScreenScraper');
        }
    }

    /**
     * Fetch a provider record, translating any transport failure into a domain one
     * so the provider's own message — which quotes the credentialed URL — is never
     * relayed to the client.
     */
    private function fetchProviderPayload(int $providerId): array
    {
        try {
            $payload = $this->screenScraper->fetchById($providerId);
        } catch (Throwable $e) {
            throw ProviderException::unavailable($e->getMessage());
        }

        if (!$payload) {
            throw ProviderException::emptyResult($providerId);
        }

        return $payload;
    }

    /**
     * Swap each artwork URL for its locally cached copy where the download worked.
     */
    private function withCachedMedia(array $payload, string $md5): array
    {
        $local = $this->mediaCache->downloadFor(
            $md5,
            Collection::make(MediaKind::cases())
                ->mapWithKeys(fn(MediaKind $kind) => [
                    $kind->value => Arr::get($payload, $kind->payloadKey()),
                ])
                ->all(),
        );

        foreach (MediaKind::cases() as $kind) {
            $payload[$kind->payloadKey()] = Arr::get($local, $kind->value)
                ?? Arr::get($payload, $kind->payloadKey());
        }

        return $payload;
    }

    /**
     * Reject a move whose destination is unknown, unchanged, or already occupied.
     */
    private function assertMovable(Console $console, Game $game, string $subfolder): void
    {
        $allowed = Collection::make($this->filesystem->listSubfolders($console))->prepend('');

        if (!$allowed->contains($subfolder)) {
            throw ValidationException::because('Unknown destination folder');
        }

        if ($this->filesystem->relativeFolder($console, $game->file_path) === $subfolder) {
            throw ValidationException::because('Game is already in that folder');
        }

        if ($this->filesystem->exists($console, $subfolder, (string) $game->file_name)) {
            throw new ConflictException(
                'A file named "' . $game->file_name . '" already exists there'
            );
        }
    }

    /**
     * Point the row at the new path, undoing the disk move if that write fails.
     */
    private function recordMove(Game $game, string $from, string $to): void
    {
        try {
            $game->update(['file_path' => $to]);
        } catch (Throwable $e) {
            if (@rename($to, $from)) {
                logger()->error('Move rolled back after a failed database update', [
                    'game'    => $game->id,
                    'message' => $e->getMessage(),
                ]);

                throw StorageException::moveFailed();
            }

            logger()->error('Move left disk and database out of sync', [
                'game'        => $game->id,
                'row_path'    => $from,
                'actual_path' => $to,
                'message'     => $e->getMessage(),
            ]);

            throw StorageException::moveOutOfSync();
        }
    }
}
