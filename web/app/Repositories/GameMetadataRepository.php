<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\GameMetadata;
use Illuminate\Support\Arr;

class GameMetadataRepository
{
    public function find(string $md5): ?GameMetadata
    {
        return GameMetadata::find($md5);
    }

    /**
     * Persist a normalized metadata payload keyed by the ROM's md5.
     *
     * $payload shape (all optional except provider_id):
     *   provider_id, title, description, cover_url, logo_url, backdrop_url,
     *   release_date, genre, players, publisher, developer, raw (array)
     */
    public function upsert(string $md5, array $payload, string $provider = 'screenscraper'): GameMetadata
    {
        $raw = Arr::get($payload, 'raw');

        GameMetadata::upsert(
            [[
                'md5'          => $md5,
                'provider'     => $provider,
                'provider_id'  => (string) Arr::get($payload, 'provider_id', ''),
                'title'        => Arr::get($payload, 'title'),
                'description'  => Arr::get($payload, 'description'),
                'cover_url'    => Arr::get($payload, 'cover_url'),
                'logo_url'     => Arr::get($payload, 'logo_url'),
                'backdrop_url' => Arr::get($payload, 'backdrop_url'),
                'release_date' => Arr::get($payload, 'release_date'),
                'genre'        => Arr::get($payload, 'genre'),
                'players'      => Arr::get($payload, 'players'),
                'publisher'    => Arr::get($payload, 'publisher'),
                'developer'    => Arr::get($payload, 'developer'),
                'raw'          => is_array($raw) ? json_encode($raw) : null,
                'fetched_at'   => time(),
            ]],
            uniqueBy: ['md5'],
            update: [
                'provider', 'provider_id', 'title', 'description',
                'cover_url', 'logo_url', 'backdrop_url', 'release_date',
                'genre', 'players', 'publisher', 'developer', 'raw', 'fetched_at',
            ],
        );

        return GameMetadata::findOrFail($md5);
    }
}
