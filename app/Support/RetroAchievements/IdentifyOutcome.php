<?php

declare(strict_types=1);

namespace App\Support\RetroAchievements;

enum IdentifyOutcome: string
{
    case Matched = 'matched';
    case NoMatch = 'no_match';
    case NeedsHash = 'needs_hash';
    case Unsupported = 'unsupported';

    /**
     * Nothing to do right now, and nothing to record.
     *
     * Distinct from Unsupported, which is an answer. This is the absence of
     * one — an unmounted disk, most often — and writing a status for it would
     * need undoing by hand once the disk came back.
     */
    case Skipped = 'skipped';
}
