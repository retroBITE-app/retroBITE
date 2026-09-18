<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    protected $model = Media::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $md5 = $this->faker->unique()->md5();

        return [
            'game_id' => Game::factory()->matched(),
            'screenscraper_type' => 'box-2D',
            'region' => 'us',
            'md5' => $md5,
            'path' => "snes/game-1234/box-2D/{$md5}.png",
            'extension' => 'png',
            'size_bytes' => $this->faker->numberBetween(10000, 900000),
            // Already stripped of credentials, as the service returns it.
            'source_url' => 'https://api.screenscraper.fr/api2/mediaJeu.php?jeuid=1234&media=box-2D%28us%29',
            'downloaded_at' => now(),
        ];
    }

    public function ofType(string $type, ?string $region = null): static
    {
        return $this->state(fn (array $attributes) => [
            'screenscraper_type' => $type,
            'region' => $region,
        ]);
    }
}
