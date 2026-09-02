<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Console;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Docs: https://www.screenscraper.fr/webapi2.php
 */
class ScreenScraperService
{
    private const SOFTNAME = 'retroBITE';
    private const DEFAULT_REGION = 'ss';
    private const PREFERRED_LANG = 'en';

    /** Region preference for box art / screenshots — English-speaking first. */
    private const MEDIA_REGION_PRIORITY = ['us', 'wor', 'eu', 'uk', 'au', 'ss'];

    public function __construct() {}

    /**
     * Look up a single game by ROM md5 + console system id.
     * Returns a normalized metadata DTO or null if no match.
     */
    public function lookupByMd5(Console $console, string $md5): ?array
    {
        if ($console->screenscraperId === null || $md5 === '') {
            return null;
        }

        $response = $this->call('jeuInfos.php', [
            'systemeid' => $console->screenscraperId,
            'md5'       => strtolower($md5),
        ]);

        $jeu = Arr::get($response, 'response.jeu');

        return is_array($jeu) ? $this->normalize($jeu) : null;
    }

    /**
     * Search for candidate matches by name + console system id.
     * Returns an array of lightweight candidate DTOs.
     *
     * @return array<int, array{provider_id: string, title: string, rom_name: ?string, region: ?string, year: ?string, cover_url: ?string}>
     */
    public function search(Console $console, string $name): array
    {
        if ($console->screenscraperId === null || trim($name) === '') {
            return [];
        }

        $response = $this->call('jeuRecherche.php', [
            'systemeid' => $console->screenscraperId,
            'recherche' => $name,
        ]);

        $jeux = Arr::get($response, 'response.jeux', []);

        if (!is_array($jeux)) {
            return [];
        }

        return Collection::make($jeux)
            ->map(fn(array $jeu) => [
                'provider_id' => (string) Arr::get($jeu, 'id', ''),
                'title'       => $this->pickLocalized(Arr::get($jeu, 'noms', []), 'text'),
                'rom_name'    => Arr::get($jeu, 'rom.romfilename'),
                'region'      => $this->firstRegion($jeu),
                'year'        => $this->extractYear($this->pickLocalized(Arr::get($jeu, 'dates', []), 'text')),
                'cover_url'   => $this->pickMedia(Arr::get($jeu, 'medias', []), ['box-2D', 'box-3D']),
            ])
            ->filter(fn(array $c) => $c['provider_id'] !== '')
            ->values()
            ->all();
    }

    /**
     * Fetch a full, normalized record for a specific ScreenScraper game id.
     */
    public function fetchById(int $gameId): ?array
    {
        if ($gameId <= 0) {
            return null;
        }

        $response = $this->call('jeuInfos.php', ['gameid' => $gameId]);
        $jeu      = Arr::get($response, 'response.jeu');

        return is_array($jeu) ? $this->normalize($jeu) : null;
    }

    /**
     * Reduce a ScreenScraper jeu object to the flat shape we persist.
     */
    private function normalize(array $jeu): array
    {
        return [
            'provider_id'  => (string) Arr::get($jeu, 'id', ''),
            'title'        => $this->pickLocalized(Arr::get($jeu, 'noms', []), 'text'),
            'description'  => $this->pickLocalized(Arr::get($jeu, 'synopsis', []), 'text', 'langue'),
            'cover_url'    => $this->pickMedia(Arr::get($jeu, 'medias', []), ['box-2D', 'box-3D']),
            'logo_url'     => $this->pickMedia(Arr::get($jeu, 'medias', []), ['wheel', 'wheel-hd', 'wheel-carbon', 'wheel-steel']),
            'backdrop_url' => $this->pickMedia(Arr::get($jeu, 'medias', []), ['fanart', 'background', 'sstitle', 'ss', 'screenmarquee']),
            'release_date' => $this->pickLocalized(Arr::get($jeu, 'dates', []), 'text'),
            'genre'        => $this->flattenGenres(Arr::get($jeu, 'genres', [])),
            'region'       => $this->firstRegion($jeu),
            'players'      => (string) Arr::get($jeu, 'joueurs.text', ''),
            'publisher'    => (string) Arr::get($jeu, 'editeur.text', ''),
            'developer'    => (string) Arr::get($jeu, 'developpeur.text', ''),
            'raw'          => $jeu,
        ];
    }

    /**
     * Pick a localized field from a list like jeu.noms / jeu.dates / jeu.synopsis.
     * Prefers region=ss for noms/dates and langue=en for synopsis, falling back to the first entry.
     */
    private function pickLocalized(mixed $list, string $valueKey, string $discriminator = 'region'): ?string
    {
        if (!is_array($list) || $list === []) {
            return null;
        }

        $preferred = $discriminator === 'langue' ? self::PREFERRED_LANG : self::DEFAULT_REGION;

        $match = Collection::make($list)
            ->first(fn($item) => is_array($item) && Arr::get($item, $discriminator) === $preferred);

        $value = Arr::get($match ?? Arr::first($list), $valueKey);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Pick the best media URL from jeu.medias for any of the given types.
     * Prefers English-region variants (us → wor → eu → …) before falling back
     * to whatever SS returns first (often fr).
     */
    private function pickMedia(mixed $medias, array $types): ?string
    {
        if (!is_array($medias)) {
            return null;
        }

        foreach ($types as $type) {
            $ofType = Collection::make($medias)
                ->filter(fn($media) => is_array($media) && Arr::get($media, 'type') === $type);

            if ($ofType->isEmpty()) {
                continue;
            }

            foreach (self::MEDIA_REGION_PRIORITY as $region) {
                $match = $ofType->first(fn($media) => Arr::get($media, 'region') === $region);
                if ($match !== null && is_string($url = Arr::get($match, 'url')) && $url !== '') {
                    return $url;
                }
            }

            $fallback = $ofType->first();
            if (is_string($url = Arr::get($fallback, 'url')) && $url !== '') {
                return $url;
            }
        }

        return null;
    }

    private function firstRegion(array $jeu): ?string
    {
        $regions = Arr::get($jeu, 'regions.regions_shortname', []);
        if (is_array($regions) && $regions !== []) {
            return (string) $regions[0];
        }

        return Arr::get($jeu, 'rom.regions.regions_shortname.0');
    }

    private function extractYear(?string $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return preg_match('/(\d{4})/', $date, $m) ? $m[1] : null;
    }

    private function flattenGenres(mixed $genres): ?string
    {
        if (!is_array($genres)) {
            return null;
        }

        $names = Collection::make($genres)
            ->map(fn($genre) => $this->pickLocalized(Arr::get($genre, 'noms', []), 'text'))
            ->filter()
            ->values()
            ->all();

        return $names === [] ? null : implode(', ', $names);
    }

    /**
     * Perform an authenticated GET against the SS v2 API, returning the decoded body.
     * Returns [] on 404 / missing game; throws on other failures.
     */
    private function call(string $endpoint, array $params): array
    {
        $creds = config('settings.screenscraper');

        if (Arr::get($creds, 'dev_id') === '' || Arr::get($creds, 'dev_password') === '') {
            throw new RuntimeException('ScreenScraper dev credentials missing — see config/settings.php.');
        }

        $query = array_filter([
            'devid'       => Arr::get($creds, 'dev_id'),
            'devpassword' => Arr::get($creds, 'dev_password'),
            'softname'    => self::SOFTNAME,
            'ssid'        => Arr::get($creds, 'user'),
            'sspassword'  => Arr::get($creds, 'password'),
            'output'      => 'json',
            ...$params,
        ], fn($v) => $v !== '' && $v !== null);

        $url = rtrim(Arr::get($creds, 'endpoint', 'https://api.screenscraper.fr/api2'), '/')
            . '/' . ltrim($endpoint, '/');

        try {
            $response = Http::withUserAgent(self::SOFTNAME)
                ->connectTimeout(5)
                ->timeout(15)
                ->get($url, $query);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new RuntimeException("ScreenScraper HTTP error: {$e->getMessage()}");
        }

        $status = $response->status();

        // SS returns 404 when a lookup misses — not an error we need to raise.
        if ($status === 404 || $status === 400) {
            return [];
        }

        if ($status >= 500) {
            throw new RuntimeException("ScreenScraper server error: HTTP {$status}");
        }

        // SS sometimes returns text errors above/below the JSON for quota/auth issues;
        // try to locate and decode the JSON envelope.
        $json = $this->extractJson($response->body());

        return is_array($json) ? $json : [];
    }

    private function extractJson(string $body): mixed
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Try to find a JSON object embedded in the response body.
        $start = strpos($body, '{');
        $end   = strrpos($body, '}');
        if ($start !== false && $end !== false && $end > $start) {
            return json_decode(substr($body, $start, $end - $start + 1), true);
        }

        return null;
    }
}
