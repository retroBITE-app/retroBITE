<?php

namespace Database\Factories;

use App\Enums\GameStatus;
use App\Models\Game;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Game>
 */
class GameFactory extends Factory
{
    protected $model = Game::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // sentence() rather than words(): words() is declared array|string
        // whatever you pass it, and a title is a string.
        $title = Str::title(rtrim($this->faker->unique()->sentence(3), '.'));

        return [
            // The default is a game nobody has identified yet, because that is
            // what a scan actually produces.
            'screenscraper_id' => null,
            'console' => 'snes',
            'title' => $title,
            'slug' => Str::slug($title),
            'status' => GameStatus::Placeholder,
            'matched_at' => null,
        ];
    }

    public function matched(?int $screenscraperId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'screenscraper_id' => $screenscraperId ?? $this->faker->unique()->numberBetween(1, 500000),
            'status' => GameStatus::Matched,
            'matched_at' => now(),
            'publisher' => $this->faker->company(),
            'developer' => $this->faker->company(),
            'release_date' => (string) $this->faker->year(),
            'region' => 'wor',
        ]);
    }

    public function unmatched(): static
    {
        return $this->state(fn (array $attributes) => [
            'screenscraper_id' => null,
            'status' => GameStatus::Unmatched,
        ]);
    }

    public function forConsole(string $console): static
    {
        return $this->state(fn (array $attributes) => ['console' => $console]);
    }
}
