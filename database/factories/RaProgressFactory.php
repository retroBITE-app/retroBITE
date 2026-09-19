<?php

namespace Database\Factories;

use App\Models\RaGame;
use App\Models\RaProgress;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RaProgress>
 */
class RaProgressFactory extends Factory
{
    protected $model = RaProgress::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'ra_game_id' => RaGame::factory(),
            'unlocked_count' => 0,
            'unlocked_hardcore_count' => 0,
            'achievements_possible' => 0,
            'points_earned' => 0,
            'points_hardcore_earned' => 0,
            'points_possible' => 0,
            'stale' => false,
        ];
    }
}
