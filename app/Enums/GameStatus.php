<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How far a game has got towards being identified.
 *
 * This is the only place matching state is recorded. Files carry facts — where
 * they are, how big they are, which disc they hold — and never state, so there
 * is no second copy of this to drift out of step with the first.
 */
enum GameStatus: string
{
    /** Found on disk, not yet looked up. The title is guessed from the filename. */
    case Placeholder = 'placeholder';

    /** ScreenScraper recognised it; screenscraper_id is set and canonical. */
    case Matched = 'matched';

    /** Looked up and genuinely not in the provider's database. */
    case Unmatched = 'unmatched';

    public function label(): string
    {
        return match ($this) {
            self::Placeholder => 'Not yet identified',
            self::Matched => 'Identified',
            self::Unmatched => 'No match found',
        };
    }

    /**
     * Whether another automatic lookup could still help.
     *
     * Unmatched is excluded on purpose: retrying it spends the failed-lookup
     * quota, which is ten times scarcer than the ordinary one. Those need a
     * manual match instead.
     */
    public function awaitingLookup(): bool
    {
        return $this === self::Placeholder;
    }
}
