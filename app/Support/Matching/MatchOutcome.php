<?php

declare(strict_types=1);

namespace App\Support\Matching;

enum MatchOutcome: string
{
    case Matched = 'matched';
    case Merged = 'merged';
    case Unmatched = 'unmatched';
    case NeedsChecksums = 'needs_checksums';
    case Skipped = 'skipped';
}
