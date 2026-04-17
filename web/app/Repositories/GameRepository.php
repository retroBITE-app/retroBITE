<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Game;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class GameRepository
{
    public function allForConsole(string $console, array $extensions = [], ?string $type = null): EloquentCollection
    {
        $query = Game::where('console', $console)
            ->havingExtensions($extensions);

        return match ($type) {
            'files' => $query->games()->get(),
            'bios'  => $query->bios()->get(),
            default => $query->get(),
        };
    }

    public function find(string $id): ?Game
    {
        return Game::find($id);
    }

    /**
     * Game + BIOS counts per console, keyed by console slug.
     *
     * @return Collection<string, array{game_count: int, bios_count: int}>
     */
    public function consoleCounts(): Collection
    {
        return Game::select(['console', 'file_path'])
            ->get()
            ->groupBy('console')
            ->map(fn(EloquentCollection $games) => [
                'game_count' => $games->reject(fn(Game $g) => $g->isBios())->count(),
                'bios_count' => $games->filter(fn(Game $g) => $g->isBios())->count(),
            ]);
    }

    /**
     * Insert or update a game record.
     */
    public function upsert(string $console, string $fileName, string $filePath, int $fileSize, ?string $fileMd5 = null): void
    {
        $now = time();

        Game::upsert(
            [[
                'id'            => $console . ':' . $fileName,
                'console'       => $console,
                'file_name'     => $fileName,
                'file_path'     => $filePath,
                'file_size'     => $fileSize,
                'file_md5'      => $fileMd5,
                'first_seen_at' => $now,
                'last_seen_at'  => $now,
            ]],
            uniqueBy: ['id'],
            update: ['file_size', 'file_md5', 'last_seen_at'],
        );
    }
}
