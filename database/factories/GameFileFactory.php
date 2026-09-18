<?php

namespace Database\Factories;

use App\Enums\FileRole;
use App\Models\Game;
use App\Models\GameFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameFile>
 */
class GameFileFactory extends Factory
{
    protected $model = GameFile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->slug(3).'.sfc';

        return [
            'game_id' => Game::factory(),
            'path' => 'snes/'.$name,
            'filename' => $name,
            'extension' => 'sfc',
            'size_bytes' => $this->faker->numberBetween(131072, 6291456),
            // Unhashed by default: scanning does not compute checksums, so an
            // unhashed row is the ordinary state rather than an edge case.
            'crc' => null,
            'md5' => null,
            'sha1' => null,
            'hashed_at' => null,
            'role' => FileRole::Rom,
            'disc_number' => null,
            'missing_since' => null,
        ];
    }

    public function hashed(): static
    {
        return $this->state(fn (array $attributes) => [
            'crc' => strtoupper($this->faker->regexify('[0-9A-F]{8}')),
            'md5' => $this->faker->md5(),
            'sha1' => $this->faker->sha1(),
            'hashed_at' => now(),
        ]);
    }

    public function missing(): static
    {
        return $this->state(fn (array $attributes) => [
            'missing_since' => now()->subDays(3),
        ]);
    }

    public function role(FileRole $role): static
    {
        return $this->state(fn (array $attributes) => ['role' => $role]);
    }

    public function disc(int $number): static
    {
        return $this->state(function (array $attributes) use ($number) {
            $name = "game (Disc {$number}).bin";

            return [
                'path' => "psx/game/{$name}",
                'filename' => $name,
                'extension' => 'bin',
                'role' => FileRole::Track,
                'disc_number' => $number,
            ];
        });
    }
}
