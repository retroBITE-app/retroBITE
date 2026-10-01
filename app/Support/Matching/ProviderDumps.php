<?php

declare(strict_types=1);

namespace App\Support\Matching;

use App\Models\Game;
use App\Models\GameFile;
use App\Support\RomRegions;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * What the provider says about each dump of a game, recorded on the files it
 * says it about.
 *
 * A jeuInfos answer lists every dump the provider knows of the title (`roms`)
 * and the one the lookup matched (`rom`), each with its region, how many
 * times it has been scraped — which is how many people hold that very dump —
 * and whether it is a beta, a hack, a translation. Nothing of that is a
 * second request: it rides in the answer that identified the game.
 *
 * A file is one of those dumps by checksum, or by exact filename, which every
 * file has without being read. A file the provider does not know keeps what
 * it had, and its region is read off its name instead.
 */
final class ProviderDumps
{
    /** The per-dump flags the provider sets to "1", as they are stored. */
    public const FLAGS = ['beta', 'demo', 'proto', 'trad', 'hack', 'unl', 'alt', 'best'];

    /**
     * Record every file's region, popularity and flags.
     *
     * @param  array<string, mixed>|null  $payload  a normalised answer; none for a game nobody answered for
     * @param  GameFile|null  $asked  the file the lookup described, which `rom` is about
     */
    public static function record(Game $game, ?array $payload, ?GameFile $asked = null): void
    {
        $known = [];

        foreach ((array) Arr::get($payload ?? [], 'roms', []) as $rom) {
            if (! is_array($rom)) {
                continue;
            }

            foreach (['romcrc', 'rommd5', 'romsha1', 'romfilename'] as $key) {
                $value = Arr::get($rom, $key);

                if (is_string($value) && $value !== '') {
                    $known[strtolower($value)] ??= $rom;
                }
            }
        }

        $matched = Arr::get($payload ?? [], 'raw.rom');

        foreach ($game->files()->get() as $file) {
            $rom = $asked !== null && $file->is($asked) && is_array($matched) ? $matched : null;

            foreach ([$file->crc, $file->md5, $file->sha1, $file->filename] as $key) {
                if ($rom === null && is_string($key) && $key !== '') {
                    $rom = $known[strtolower($key)] ?? null;
                }
            }

            $facts = $rom !== null ? self::facts($rom) : [];

            // The provider's region wins; a name never replaces a region
            // already recorded.
            $facts['region'] = $facts['region']
                ?? $file->region
                ?? RomRegions::fromFilename((string) $file->filename)[0]
                ?? null;

            $file->fill($facts);

            if ($file->isDirty()) {
                $file->save();
            }
        }

        if ($payload !== null) {
            $game->forceFill(['dumps_recorded_at' => now()])->save();
        }
    }

    /**
     * What one of the provider's dumps says about itself.
     *
     * @param  array<string, mixed>  $rom
     * @return array{region?: string|null, scrapes: int|null, provider_flags: list<string>}
     */
    private static function facts(array $rom): array
    {
        $region = Arr::get($rom, 'regions.regions_shortname.0') ?? Str::before((string) Arr::get($rom, 'romregions', ''), ',');
        $scrapes = Arr::get($rom, 'nbscrap');

        return [
            'region' => is_string($region) && trim($region) !== '' ? strtolower(trim($region)) : null,
            'scrapes' => is_numeric($scrapes) ? (int) $scrapes : null,
            'provider_flags' => array_values(array_filter(
                self::FLAGS,
                fn (string $flag): bool => (string) Arr::get($rom, $flag, '0') === '1',
            )),
        ];
    }
}
