<?php

namespace Database\Factories;

use App\Models\RaGame;
use App\Models\RaGameHash;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RaGameHash>
 */
class RaGameHashFactory extends Factory
{
    protected $model = RaGameHash::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hash' => $this->faker->unique()->md5(),
            'ra_game_id' => RaGame::factory(),
            'ra_console_id' => 3,
        ];
    }
}
