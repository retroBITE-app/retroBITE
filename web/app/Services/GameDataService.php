<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Game;
use App\Models\GameMetadata;
use Illuminate\Support\Collection;

class GameDataService
{
    public function __construct(
        private FilesystemService $filesystem,
    ) {}

    /**
     * Enrich a single game with provider metadata (when available) and region data.
     * Provider fields win over any column on Game.
     */
    public function enrichGame(Game $game): array
    {
        $meta = $game->metadata;

        $region     = $this->filesystem->resolveRegion($game->file_name) ?? 'unknown';
        $regionMeta = config("regions.{$region}");

        return [
            'title'         => $this->pick($meta, 'title')        ?? $game->title ?? $game->file_name,
            'description'   => $this->pick($meta, 'description')  ?? $game->description ?? null,
            'cover_url'     => $this->pick($meta, 'cover_url')    ?? $game->cover_url ?? null,
            'logo_url'      => $this->pick($meta, 'logo_url')     ?? $game->logo_url ?? null,
            'backdrop_url'  => $this->pick($meta, 'backdrop_url') ?? null,
            'release_date'  => $this->pick($meta, 'release_date') ?? $game->release_date ?? null,
            'genre'         => $this->pick($meta, 'genre'),
            'players'       => $this->pick($meta, 'players'),
            'publisher'     => $this->pick($meta, 'publisher'),
            'developer'     => $this->pick($meta, 'developer'),
            'first_seen_at' => $game->first_seen_at,
            'last_seen_at'  => $game->last_seen_at,
            'region'        => $region,
            'regionMeta'    => $regionMeta,
            'file_name'     => $game->file_name,
            'file_size'     => $game->file_size,
            'file_md5'      => $game->file_md5 ?? null,
            'identified_at' => $meta?->fetched_at,
        ];
    }

    public function enrichGames(Collection $games): Collection
    {
        return $games->map(fn(Game $game) => $this->enrichGame($game));
    }

    private function pick(?GameMetadata $meta, string $field): ?string
    {
        $value = $meta?->{$field};

        return is_string($value) && $value !== '' ? $value : null;
    }
}
