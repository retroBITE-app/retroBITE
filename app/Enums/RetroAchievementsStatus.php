<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How far a game has got towards having an achievement set.
 *
 * Deliberately separate from GameStatus: the two providers answer different
 * questions and one of them failing must not make the other look unanswered.
 * A game ScreenScraper has never heard of can still have a set here.
 */
enum RetroAchievementsStatus: string
{
    /** Not asked yet. */
    case Pending = 'pending';

    /**
     * The console has no retroachievements_id.
     *
     * Final until the mapping changes, which is why it is not NoMatch: there
     * is no question to re-ask, only a config entry to fill in.
     */
    case Unsupported = 'unsupported';

    /** RAHasher could not produce a hash for this game's file. */
    case HashFailed = 'hash_failed';

    /**
     * Hashed, and the hash is not in the index.
     *
     * Never final. Sets are added to RetroAchievements constantly, and the
     * expensive part — the hash — is already cached, so the nightly index sync
     * rematches these for free.
     */
    case NoMatch = 'no_match';

    /** retroachievements_id is set. */
    case Matched = 'matched';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Not yet checked',
            self::Unsupported => 'Console not on RetroAchievements',
            self::HashFailed => 'Could not be hashed',
            self::NoMatch => 'No achievement set yet',
            self::Matched => 'Has achievements',
        };
    }

    /**
     * Whether another automatic attempt could still help.
     *
     * Unsupported is excluded because nothing will change until somebody edits
     * the console; HashFailed because the same file will fail the same way.
     */
    public function awaitingLookup(): bool
    {
        return $this === self::Pending || $this === self::NoMatch;
    }
}
