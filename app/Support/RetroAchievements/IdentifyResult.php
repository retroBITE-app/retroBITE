<?php

declare(strict_types=1);

namespace App\Support\RetroAchievements;

use App\Models\Game;
use App\Models\GameFile;

/**
 * What happened when a game was looked up in the hash index.
 *
 * Shaped like App\Support\Matching\MatchResult, including the outcome that
 * asks the caller to go away and come back: hashing is minutes of disk work
 * and belongs on a different queue from the lookup that needed it.
 */
final class IdentifyResult
{
    private function __construct(
        public readonly IdentifyOutcome $outcome,
        public readonly ?Game $game = null,
        public readonly ?GameFile $file = null,
        public readonly ?int $raGameId = null,
        public readonly ?string $hash = null,
        public readonly ?string $reason = null,
    ) {}

    public static function matched(Game $game, int $raGameId, string $hash): self
    {
        return new self(IdentifyOutcome::Matched, game: $game, raGameId: $raGameId, hash: $hash);
    }

    /**
     * Hashed, and the hash is in no set we know of.
     *
     * Not final: the nightly index sync rematches these without hashing again.
     */
    public static function noMatch(Game $game, string $hash): self
    {
        return new self(IdentifyOutcome::NoMatch, game: $game, hash: $hash);
    }

    public static function needsHash(Game $game, GameFile $file): self
    {
        return new self(IdentifyOutcome::NeedsHash, game: $game, file: $file);
    }

    /** No console mapping, or no file RAHasher could read. */
    public static function unsupported(Game $game, string $reason): self
    {
        return new self(IdentifyOutcome::Unsupported, game: $game, reason: $reason);
    }

    /** Ask again later, and record nothing in the meantime. */
    public static function skipped(Game $game, string $reason): self
    {
        return new self(IdentifyOutcome::Skipped, game: $game, reason: $reason);
    }
}
