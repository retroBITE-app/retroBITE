<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\GameStatus;
use App\Exceptions\ScreenScraper\ScreenScraperException;
use App\Models\Game;
use App\Models\GameFile;
use App\Support\Matching\MatchResult;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Puts a game to ScreenScraper and records what comes back.
 *
 * Asks once per game, never once per file. Every disc of a four-disc set
 * answers to the same provider id, so three of those four lookups would buy
 * nothing — and each one that missed would cost against the failed-lookup
 * allowance, which is 2 000 a day against 20 000 for successful ones.
 *
 * Name and size are tried before any checksum. They are free, the provider
 * accepts them, and they identify most of a tidily named library; reading four
 * gigabytes to produce an MD5 is only worth it for the files that miss.
 */
final class GameMatcher
{
    /** Extensions the provider wants described as a disc rather than a cartridge. */
    private const DISC_EXTENSIONS = [
        'iso', 'bin', 'cue', 'chd', 'gdi', 'cdi', 'img', 'mdf', 'nrg', 'ccd',
        'cso', 'zso', 'pbp', 'wbfs', 'gcm', 'gcz', 'ciso', 'rvz', 'toc',
    ];

    public function __construct(private readonly ScreenScraperService $provider) {}

    /**
     * @throws ScreenScraperException when the provider could not be asked at all
     */
    public function match(Game $game, bool $withChecksums = false): MatchResult
    {
        $console = $game->console();

        if ($console === null || $console->screenscraperId === null) {
            return MatchResult::skipped($game, 'console is not mapped to the provider');
        }

        $file = $game->identifiableFile();

        if ($file === null) {
            return MatchResult::skipped($game, 'no file that the provider could identify');
        }

        $criteria = $this->criteriaFor($file, $withChecksums);
        $payload = $this->provider->lookup($console, $criteria);

        if ($payload === null) {
            return $this->handleMiss($game, $file, $criteria, $withChecksums);
        }

        $this->log($game, 'hit', $criteria, ['provider_id' => Arr::get($payload, 'provider_id')]);

        return $this->apply($game, $payload);
    }

    /**
     * A miss is only final once checksums have been tried.
     *
     * @param  array<string, mixed>  $criteria
     */
    private function handleMiss(Game $game, GameFile $file, array $criteria, bool $withChecksums): MatchResult
    {
        if (! $withChecksums && ! $file->isHashed()) {
            // Not recorded as a failure yet: the question has not been fully
            // asked, and logging it as unmatched would be misleading later.
            return MatchResult::needsChecksums($game, $file);
        }

        if (! $withChecksums) {
            return $this->match($game, withChecksums: true);
        }

        // Kept in full because this is the row somebody opens to ask why a
        // game was never identified. What was sent is the whole answer.
        $this->log($game, 'miss', $criteria);

        $game->update(['status' => GameStatus::Unmatched]);

        return MatchResult::unmatched($game);
    }

    /**
     * Fold the provider's answer into the library.
     *
     * @param  array<string, mixed>  $payload
     */
    private function apply(Game $game, array $payload): MatchResult
    {
        $providerId = (int) Arr::get($payload, 'provider_id');

        if ($providerId <= 0) {
            $game->update(['status' => GameStatus::Unmatched]);

            return MatchResult::unmatched($game);
        }

        $existing = Game::query()
            ->where('screenscraper_id', $providerId)
            ->whereKeyNot($game->getKey())
            ->first();

        // Another region or dump of a game already in the library. The provider
        // id is the identity, so these are the same game and the placeholder
        // that was made for this file has no reason to survive.
        if ($existing !== null) {
            return DB::transaction(function () use ($game, $existing, $payload) {
                $game->files()->update(['game_id' => $existing->id]);
                // Any media rows go with the game through the cascade. In the
                // automatic flow there are none: artwork is only fetched once a
                // game has been identified, which is after this point.
                $game->delete();

                $this->applyDiscNumbers($existing, $payload);

                return MatchResult::merged($existing);
            });
        }

        $game->update([
            'screenscraper_id' => $providerId,
            'title' => Arr::get($payload, 'title') ?: $game->title,
            'slug' => $this->slugFor($game, (string) (Arr::get($payload, 'title') ?: $game->title)),
            'status' => GameStatus::Matched,
            'matched_at' => now(),
            'description' => Arr::get($payload, 'description'),
            'release_date' => Arr::get($payload, 'release_date'),
            'genre' => Arr::get($payload, 'genre'),
            'players' => Arr::get($payload, 'players') ?: null,
            'publisher' => Arr::get($payload, 'publisher') ?: null,
            'developer' => Arr::get($payload, 'developer') ?: null,
            'region' => Arr::get($payload, 'region'),
        ]);

        $this->applyDiscNumbers($game, $payload);

        return MatchResult::matched($game->refresh());
    }

    /**
     * Fill in disc numbers from the provider's own list of known dumps.
     *
     * Matched on checksum, because filenames differ between dump sets. Only
     * romnumsupport is trusted: romtotalsupport is contributed per entry and
     * the same game carries /4, /6, /7 and /13 across different submissions.
     *
     * @param  array<string, mixed>  $payload
     */
    private function applyDiscNumbers(Game $game, array $payload): void
    {
        $roms = Arr::get($payload, 'roms');

        if (! is_array($roms) || $roms === []) {
            return;
        }

        $byChecksum = [];

        foreach ($roms as $rom) {
            if (! is_array($rom)) {
                continue;
            }

            $disc = (int) Arr::get($rom, 'romnumsupport', 0);

            if ($disc <= 0) {
                continue;
            }

            foreach (['romcrc' => 'crc', 'rommd5' => 'md5', 'romsha1' => 'sha1'] as $key => $_) {
                $value = Arr::get($rom, $key);

                if (is_string($value) && $value !== '') {
                    $byChecksum[strtolower($value)] = $disc;
                }
            }
        }

        if ($byChecksum === []) {
            return;
        }

        foreach ($game->files()->whereNotNull('md5')->get() as $file) {
            foreach ([$file->crc, $file->md5, $file->sha1] as $sum) {
                if ($sum !== null && isset($byChecksum[strtolower($sum)])) {
                    $file->update(['disc_number' => $byChecksum[strtolower($sum)]]);

                    break;
                }
            }
        }
    }

    /**
     * What to ask the provider with.
     *
     * @return array<string, mixed>
     */
    private function criteriaFor(GameFile $file, bool $withChecksums): array
    {
        $criteria = [
            'romnom' => $file->filename,
            'romtaille' => $file->size_bytes,
            'romtype' => in_array($file->extension, self::DISC_EXTENSIONS, true) ? 'iso' : 'rom',
        ];

        if ($withChecksums) {
            $criteria += array_filter([
                'crc' => $file->crc,
                'md5' => $file->md5,
                'sha1' => $file->sha1,
            ]);
        }

        return $criteria;
    }

    /** A slug free on this console, keeping the game's own if it already holds it. */
    private function slugFor(Game $game, string $title): string
    {
        $base = Str::slug($title) ?: $game->slug;
        $slug = $base;
        $suffix = 1;

        while (Game::query()
            ->where('console', $game->console)
            ->where('slug', $slug)
            ->whereKeyNot($game->getKey())
            ->exists()
        ) {
            $suffix++;
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @param  array<string, mixed>  $extra
     */
    private function log(Game $game, string $outcome, array $criteria, array $extra = []): void
    {
        activity('screenscraper')
            ->performedOn($game)
            ->withProperties([
                'endpoint' => 'jeuInfos.php',
                // No credentials here: the service adds those itself and they
                // never enter this array.
                'criteria' => $criteria,
                'outcome' => $outcome,
            ] + $extra)
            ->log("jeuInfos {$outcome}");
    }
}
