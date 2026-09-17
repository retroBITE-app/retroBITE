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
    private function __construct(
        public readonly MatchOutcome $outcome,
        public readonly ?Game $game = null,
        public readonly ?GameFile $file = null,
        public readonly ?string $reason = null,
    ) {}

    public static function matched(Game $game): self
    {
        return new self(MatchOutcome::Matched, game: $game);
    }

    /** The game turned out to be one already in the library, and was folded into it. */
    public static function merged(Game $into): self
    {
        return new self(MatchOutcome::Merged, game: $into);
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
