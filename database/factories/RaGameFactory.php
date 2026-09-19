<?php

namespace Database\Factories;

use App\Models\RaGame;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RaGame>
 */
class RaGameFactory extends Factory
{
    protected $model = RaGame::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Explicit, because the key is RetroAchievements' own and the
            // table does not auto-increment.
            'id' => $this->faker->unique()->numberBetween(1, 500000),
            'ra_console_id' => 3,
            'title' => Str::title(rtrim($this->faker->unique()->sentence(3), '.')),
            'num_achievements' => 0,
            'points_total' => 0,
            'num_distinct_players' => 1000,
            'num_distinct_players_hardcore' => 400,
        ];
    }

    public function synced(): static
    {
        return $this->state(fn (array $attributes) => ['set_synced_at' => now()]);
    }
}
