<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\MediaKind;
use App\Models\GameMetadata;
use Illuminate\Support\Arr;

class GameMetadataRepository
{
    /** Default provider, matching the column default in 004_create_game_metadata.sql. */
    private const DEFAULT_PROVIDER = 'screenscraper';

    /** Text fields copied straight out of a normalized payload. */
    private const TEXT_FIELDS = [
        'title',
        'description',
        'release_date',
        'genre',
        'players',
        'publisher',
        'developer',
    ];

    /**
     * The metadata row for a ROM md5, or null.
     */
    public function find(string $md5): ?GameMetadata
    {
        return GameMetadata::find($md5);
    }

    /**
     * A cached backdrop URL picked at random, or null when nothing is cached.
     *
     * The login page paints one behind its art pane, so a fresh install with no
     * scraped metadata gets null and renders the pane without an image.
     */
    public function randomBackdropUrl(): ?string
    {
        $url = GameMetadata::whereNotNull('backdrop_url')
            ->where('backdrop_url', '!=', '')
            ->inRandomOrder()
            ->value('backdrop_url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Persist a normalized metadata payload keyed by the ROM's md5.
     */
    public function upsert(string $md5, array $payload, string $provider = self::DEFAULT_PROVIDER): GameMetadata
    {
        $row = $this->toRow($md5, $payload, $provider);

        GameMetadata::upsert(
            [$row],
            uniqueBy: ['md5'],
            update: array_keys(Arr::except($row, ['md5'])),
        );

        return GameMetadata::findOrFail($md5);
    }

    /**
     * Flatten a payload into the table's columns, in a fixed order so the upsert
     * always names the same set.
     */
    private function toRow(string $md5, array $payload, string $provider): array
    {
        $row = [
            'md5'         => $md5,
            'provider'    => $provider,
            'provider_id' => (string) Arr::get($payload, 'provider_id', ''),
        ];

        foreach (self::TEXT_FIELDS as $field) {
            $row[$field] = Arr::get($payload, $field);
        }

        foreach (MediaKind::cases() as $kind) {
            $row[$kind->payloadKey()] = Arr::get($payload, $kind->payloadKey());
        }

        $raw = Arr::get($payload, 'raw');

        $row['raw']        = is_array($raw) ? json_encode($raw, JSON_THROW_ON_ERROR) : null;
        $row['fetched_at'] = time();

        return $row;
    }
}
