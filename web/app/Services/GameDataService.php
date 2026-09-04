<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Game;
use App\Models\GameMetadata;
use App\Support\Region;
use Illuminate\Support\Collection;

class GameDataService
{
    /**
     * Flatten a game plus its provider metadata into the shape the frontend reads.
     * Provider fields win over any column on the game row.
     */
    public function enrichGame(Game $game): array
    {
        $meta   = $game->metadata;
        $region = Region::fromFilename((string) $game->file_name);

        return [
            'title'         => $this->pick($meta, 'title') ?? $game->title ?? $game->file_name,
            'description'   => $this->pick($meta, 'description'),
            'cover_url'     => $this->pick($meta, 'cover_url'),
            'logo_url'      => $this->pick($meta, 'logo_url'),
            'backdrop_url'  => $this->pick($meta, 'backdrop_url'),
            'release_date'  => $this->pick($meta, 'release_date'),
            'genre'         => $this->pick($meta, 'genre'),
            'players'       => $this->pick($meta, 'players'),
            'publisher'     => $this->pick($meta, 'publisher'),
            'developer'     => $this->pick($meta, 'developer'),
            'first_seen_at' => $game->first_seen_at,
            'last_seen_at'  => $game->last_seen_at,
            'region'        => $region?->key,
            'region_meta'   => $region?->toArray(),
            'file_name'     => $game->file_name,
            'file_size'     => $game->file_size,
            'file_md5'      => $game->file_md5,
            'is_bios'       => $game->isBios(),
            'identified_at' => $meta?->fetched_at,
        ];
    }

    /**
     * @param Collection<int, Game> $games
     */
    public function enrichGames(Collection $games): Collection
    {
        return $games->map(fn(Game $game) => $this->enrichGame($game));
    }

    /**
     * A non-empty string field from the metadata row, or null.
     */
    private function pick(?GameMetadata $meta, string $field): ?string
    {
        $value = $meta?->{$field};

        return is_string($value) && $value !== '' ? $value : null;
    }
}
