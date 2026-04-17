<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Inertia\Inertia;
use App\Repositories\GameMetadataRepository;
use App\Repositories\GameRepository;
use App\Services\GameDataService;
use App\Services\MediaCacheService;
use App\Services\ScreenScraperService;
use App\Support\Console;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

class GameController
{
    public function __construct(
        private GameRepository $games,
        private GameMetadataRepository $metadataRepo,
        private GameDataService $gameDataService,
        private ScreenScraperService $screenScraper,
        private MediaCacheService $mediaCache,
    ) {}

    public function show(Request $_request, Response $response, array $args): Response
    {
        $console = Console::tryFrom(Arr::get($args, 'console'));

        if (!$console) {
            return $response->withStatus(404);
        }

        $game = $this->games->find($console->key . ':' . Arr::get($args, 'game'));

        if ($game === null) {
            return $response->withStatus(404);
        }

        return Inertia::render($response, 'Games/Show', [
            'console' => $console->key,
            'meta'    => config("consoles.{$console->key}"),
            'game'    => $this->gameDataService->enrichGame($game),
        ]);
    }

    /**
     * Trigger a ScreenScraper identification for a game. Returns a candidate list
     * the user picks from via the Identify modal. Never auto-persists.
     */
    public function identify(Request $request, Response $response, array $args): Response
    {
        $console = Console::tryFrom(Arr::get($args, 'console'));
        if (!$console) {
            return $this->jsonError($response, 'Unknown console', 404);
        }

        if ($console->screenscraperId === null) {
            return $this->jsonError($response, 'Console not mapped to ScreenScraper', 422);
        }

        $game = $this->games->find($console->key . ':' . Arr::get($args, 'game'));
        if (!$game) {
            return $this->jsonError($response, 'Unknown game', 404);
        }

        $override    = trim((string) Arr::get($request->getQueryParams(), 'search', ''));
        $searchName  = $override !== '' ? $override : $this->cleanName($game->file_name);

        try {
            $md5Match = $override === '' && $game->file_md5
                ? $this->screenScraper->lookupByMd5($console, $game->file_md5)
                : null;

            $candidates = $this->screenScraper->search($console, $searchName);
        } catch (Throwable $e) {
            return $this->jsonError($response, 'ScreenScraper request failed: ' . $e->getMessage(), 502);
        }

        return $this->json($response, [
            'md5Match'   => $md5Match,
            'candidates' => $candidates,
        ]);
    }

    /**
     * Persist the metadata for a user-chosen ScreenScraper match.
     */
    public function assignMetadata(Request $request, Response $response, array $args): Response
    {
        $console = Console::tryFrom(Arr::get($args, 'console'));
        if (!$console) {
            return $this->jsonError($response, 'Unknown console', 404);
        }

        $game = $this->games->find($console->key . ':' . Arr::get($args, 'game'));
        if (!$game || !$game->file_md5) {
            return $this->jsonError($response, 'Game must have an md5 before identification', 422);
        }

        $providerId = (int) Arr::get($request->getParsedBody() ?? [], 'provider_id', 0);
        if ($providerId <= 0) {
            return $this->jsonError($response, 'provider_id required', 422);
        }

        try {
            $payload = $this->screenScraper->fetchById($providerId);
        } catch (Throwable $e) {
            return $this->jsonError($response, 'ScreenScraper request failed: ' . $e->getMessage(), 502);
        }

        if (!$payload) {
            return $this->jsonError($response, 'ScreenScraper returned no result for that id', 502);
        }

        $local = $this->mediaCache->downloadFor($game->file_md5, [
            'cover'    => Arr::get($payload, 'cover_url'),
            'logo'     => Arr::get($payload, 'logo_url'),
            'backdrop' => Arr::get($payload, 'backdrop_url'),
        ]);

        $payload['cover_url']    = Arr::get($local, 'cover')    ?? Arr::get($payload, 'cover_url');
        $payload['logo_url']     = Arr::get($local, 'logo')     ?? Arr::get($payload, 'logo_url');
        $payload['backdrop_url'] = Arr::get($local, 'backdrop') ?? Arr::get($payload, 'backdrop_url');

        $metadata = $this->metadataRepo->upsert($game->file_md5, $payload);

        return $this->json($response, [
            'status'   => 'ok',
            'metadata' => [
                'title'        => $metadata->title,
                'provider_id'  => $metadata->provider_id,
                'fetched_at'   => $metadata->fetched_at,
            ],
        ]);
    }

    /**
     * Permanently delete a game: unlinks the file on disk and removes the
     * game + any associated metadata row. Not reversible.
     */
    public function destroy(Request $_request, Response $response, array $args): Response
    {
        $console = Console::tryFrom(Arr::get($args, 'console'));
        if (!$console) {
            return $this->jsonError($response, 'Unknown console', 404);
        }

        $game = $this->games->find($console->key . ':' . Arr::get($args, 'game'));
        if (!$game) {
            return $this->jsonError($response, 'Unknown game', 404);
        }

        try {
            if (is_file($game->file_path)) {
                unlink($game->file_path);
            }
            $game->delete();
        } catch (Throwable $e) {
            return $this->jsonError($response, 'Delete failed: ' . $e->getMessage(), 500);
        }

        return $this->json($response, ['status' => 'ok']);
    }

    /**
     * Derive a searchable name from a ROM filename by stripping the extension,
     * bracketed tag groups ("(Europe)", "[!]"), a leading product-code prefix
     * ("SLUS-20576.", "SLES_527.25.", "SCUS-97112 - "), and trailing disc hints.
     */
    private function cleanName(string $filename): string
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);

        $base = preg_replace('/\s*[\(\[][^\)\]]*[\)\]]/', '', $base) ?? $base;
        $base = preg_replace('/^[A-Z]{3,5}[-_][0-9]{3,5}(\.[0-9]+)?\s*[-.]\s*/', '', $base) ?? $base;
        $base = preg_replace('/\s*[-_]\s*Disc\s*\d+.*$/i', '', $base) ?? $base;

        return trim(Str::of($base)->replaceMatches('/\s+/', ' ')->toString());
    }

    private function json(Response $response, array $body, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($body));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function jsonError(Response $response, string $message, int $status): Response
    {
        return $this->json($response, ['error' => $message], $status);
    }
}
