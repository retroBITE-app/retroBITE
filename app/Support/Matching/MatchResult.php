<?php

declare(strict_types=1);

namespace App\Support\Matching;

use App\Models\Game;
use App\Models\GameFile;

/**
 * What happened when a game was put to the provider.
 */
final class MatchResult
{
    /**
     * @param  array<int, array<string, mixed>>  $medias  carried from the answer, so
     *                                                    fetching artwork costs no second request
     */
    private function __construct(
        public readonly MatchOutcome $outcome,
        public readonly ?Game $game = null,
        public readonly ?GameFile $file = null,
        public readonly ?string $reason = null,
        public readonly array $medias = [],
    ) {}

    /** @param  array<int, array<string, mixed>>  $medias */
    public static function matched(Game $game, array $medias = []): self
    {
        return new self(MatchOutcome::Matched, game: $game, medias: $medias);
    }

    /**
     * The game turned out to be one already in the library, and was folded into it.
     *
     * @param  array<int, array<string, mixed>>  $medias
     */
    public static function merged(Game $into, array $medias = []): self
    {
        return new self(MatchOutcome::Merged, game: $into, medias: $medias);
    }

    public static function unmatched(Game $game): self
    {
        return new self(MatchOutcome::Unmatched, game: $game);
    }

    /** Name and size were not enough; the file has to be checksummed first. */
    public static function needsChecksums(Game $game, GameFile $file): self
    {
        return new self(MatchOutcome::NeedsChecksums, game: $game, file: $file);
    }

    public static function skipped(Game $game, string $reason): self
    {
        return new self(MatchOutcome::Skipped, game: $game, reason: $reason);
    }
}
