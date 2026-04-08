<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Game;
use Illuminate\Support\Collection;

class GameDataService
{
    public function __construct(
        private FilesystemService $filesystem,
    ) {}

    /**
     * Enrich a single game with region data and format fields
     */
    public function enrichGame(Game $game): array
    {
        $region     = $this->filesystem->resolveRegion($game->file_name) ?? 'unknown';
        $regionMeta = config("regions.{$region}");

        return [
            'title'         => $game->title ?? $game->file_name,
            'description'   => $game->description ?? null,
            'cover_url'     => $game->cover_url ?? null,
            'logo_url'      => $game->logo_url ?? null,
            'release_date'  => $game->release_date ?? null,
            'first_seen_at' => $game->first_seen_at,
            'last_seen_at'  => $game->last_seen_at,
            'region'        => $region,
            'regionMeta'    => $regionMeta,
            'file_name'     => $game->file_name,
            'file_size'     => $game->file_size,
            'file_md5'      => $game->file_md5 ?? null,
        ];
    }

    /**
     * Enrich a collection of games with region data
     */
    public function enrichGames(Collection $games): Collection
    {
        return $games->map(fn(Game $game) => $this->enrichGame($game));
    }
}