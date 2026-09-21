<?php

namespace Database\Factories;

use App\Enums\AchievementKind;
use App\Models\RaAchievement;
use App\Models\RaGame;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RaAchievement>
 */
class RaAchievementFactory extends Factory
{
    protected $model = RaAchievement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => $this->faker->unique()->numberBetween(1, 5000000),
            'ra_game_id' => RaGame::factory(),
            'title' => Str::title(rtrim($this->faker->unique()->sentence(3), '.')),
            'description' => $this->faker->sentence(),
            'points' => $this->faker->randomElement([1, 3, 5, 10, 25, 50, 100]),
            'true_ratio' => $this->faker->numberBetween(1, 200),
            'badge_name' => (string) $this->faker->numberBetween(10000, 99999),
            'kind' => null,
            'display_order' => 0,
            'core' => true,
            'num_awarded' => $this->faker->numberBetween(0, 800),
            'num_awarded_hardcore' => $this->faker->numberBetween(0, 300),
            'removed_at' => null,
        ];
    }

    /** Stored but never counted, which is the point of the state. */
    public function unofficial(): static
    {
        return $this->state(fn (array $attributes) => ['core' => false]);
    }

    /** Gone from the set, still on the record. */
    public function removed(): static
    {
        return $this->state(fn (array $attributes) => ['removed_at' => now()]);
    }

    public function worth(int $points): static
    {
        return $this->state(fn (array $attributes) => ['points' => $points]);
    }

    public function kind(AchievementKind $kind): static
    {
        return $this->state(fn (array $attributes) => ['kind' => $kind]);
    }
}
