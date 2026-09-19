<?php

namespace Database\Factories;

use App\Models\RaAchievement;
use App\Models\RaUnlock;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RaUnlock>
 */
class RaUnlockFactory extends Factory
{
    protected $model = RaUnlock::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'ra_achievement_id' => RaAchievement::factory(),
            'ra_game_id' => 0,
            'unlocked_at' => now()->subDays(3),
            'unlocked_hardcore_at' => null,
        ];
    }

    /** Unlocked in hardcore, which counts as softcore too. */
    public function hardcore(): static
    {
        return $this->state(fn (array $attributes) => [
            'unlocked_at' => $attributes['unlocked_at'] ?? now()->subDays(3),
            'unlocked_hardcore_at' => $attributes['unlocked_at'] ?? now()->subDays(3),
        ]);
    }

    public function forAchievement(RaAchievement $achievement): static
    {
        return $this->state(fn (array $attributes) => [
            'ra_achievement_id' => $achievement->id,
            'ra_game_id' => $achievement->ra_game_id,
        ]);
    }
}
