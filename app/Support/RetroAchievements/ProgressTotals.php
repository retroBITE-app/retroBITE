<?php

declare(strict_types=1);

namespace App\Support\RetroAchievements;

use Illuminate\Support\Carbon;

/**
 * The counters for one person in one set, worked out from the unlock rows.
 *
 * Exists so that the recompute can be written once and used by both the
 * incremental sync and the rebuild-from-scratch command, and so a test can
 * compare the two without going through the database twice.
 */
final class ProgressTotals
{
    public function __construct(
        public readonly int $unlockedCount = 0,
        public readonly int $unlockedHardcoreCount = 0,
        public readonly int $achievementsPossible = 0,
        public readonly int $pointsEarned = 0,
        public readonly int $pointsHardcoreEarned = 0,
        public readonly int $pointsPossible = 0,
        public readonly ?Carbon $lastUnlockAt = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'unlocked_count' => $this->unlockedCount,
            'unlocked_hardcore_count' => $this->unlockedHardcoreCount,
            'achievements_possible' => $this->achievementsPossible,
            'points_earned' => $this->pointsEarned,
            'points_hardcore_earned' => $this->pointsHardcoreEarned,
            'points_possible' => $this->pointsPossible,
            'last_unlock_at' => $this->lastUnlockAt,
        ];
    }
}
