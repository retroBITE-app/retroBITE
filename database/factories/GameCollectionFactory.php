<?php

namespace Database\Factories;

use App\Models\GameCollection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GameCollection>
 */
class GameCollectionFactory extends Factory
{
    protected $model = GameCollection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(rtrim($this->faker->unique()->sentence(2), '.'));

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => null,
        ];
    }
}
