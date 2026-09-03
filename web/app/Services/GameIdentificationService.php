<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MediaKind;
use App\Exceptions\ProviderException;
use App\Exceptions\ValidationException;
use App\Models\Game;
use App\Models\GameMetadata;
use App\Repositories\GameMetadataRepository;
use App\Support\Console;
use App\Support\RomFilename;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Looks a game up with the metadata provider and persists the match the user picks.
 */
class GameIdentificationService
{
    public function __construct(
        private ScreenScraperService $provider,
        private MediaCacheService $mediaCache,
        private GameMetadataRepository $metadata,
    ) {}

    /**
     * Candidate matches for a game: an exact md5 hit where we have one, plus a
     * name search. Never persists anything.
     *
     * @return array{md5_match: ?array, candidates: array}
     * @throws ValidationException|ProviderException
     */
    public function candidatesFor(Console $console, Game $game, string $searchOverride = ''): array
    {
        $this->assertScrapable($console);

        $searchName = $searchOverride !== ''
            ? $searchOverride
            : RomFilename::toSearchName((string) $game->file_name);

        return $this->guard(fn() => [
            'md5_match'  => $searchOverride === '' && $game->file_md5
                ? $this->provider->lookupByMd5($console, $game->file_md5)
                : null,
            'candidates' => $this->provider->search($console, $searchName),
        ]);
    }

    /**
     * Fetch and store the metadata for a chosen provider record, caching its
     * artwork locally on the way.
     *
     * @throws ValidationException|ProviderException
     */
    public function assign(Game $game, int $providerId): GameMetadata
    {
        if (!$game->file_md5) {
            throw ValidationException::because('Game must have an md5 before identification');
        }

        if ($providerId <= 0) {
            throw ValidationException::fields(['provider_id' => 'Required']);
        }

        $payload = $this->guard(fn() => $this->provider->fetchById($providerId));

        if (!$payload) {
            throw ProviderException::emptyResult($providerId);
        }

        return $this->metadata->upsert(
            $game->file_md5,
            $this->withCachedMedia($payload, $game->file_md5),
        );
    }

    /**
     * Reject a console the provider has no system id for.
     *
     * @throws ValidationException
     */
    private function assertScrapable(Console $console): void
    {
        if ($console->screenscraperId === null) {
            throw ValidationException::because('Console not mapped to ScreenScraper');
        }
    }

    /**
     * Run a provider call, converting any transport failure into a domain one —
     * the provider's own message quotes the credentialed request URL.
     *
     * @template T
     * @param callable(): T $call
     * @return T
     * @throws ProviderException
     */
    private function guard(callable $call): mixed
    {
        try {
            return $call();
        } catch (Throwable $e) {
            throw ProviderException::unavailable($e->getMessage());
        }
    }

    /**
     * Swap each artwork URL for its locally cached copy where the download worked.
     */
    private function withCachedMedia(array $payload, string $md5): array
    {
        $sources = Collection::make(MediaKind::cases())
            ->mapWithKeys(fn(MediaKind $kind) => [$kind->value => Arr::get($payload, $kind->payloadKey())])
            ->all();

        $cached = $this->mediaCache->downloadFor($md5, $sources);

        foreach (MediaKind::cases() as $kind) {
            $payload[$kind->payloadKey()] = Arr::get($cached, $kind->value)
                ?? Arr::get($payload, $kind->payloadKey());
        }

        return $payload;
    }
}
