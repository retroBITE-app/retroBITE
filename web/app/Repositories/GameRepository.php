<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Game;
use Illuminate\Database\Eloquent\Collection;

class GameRepository
{
    public function allForConsole(string $console, array $extensions = [], ?string $type = null): Collection
    {
        $query = Game::where('console', $console);

        if (!empty($extensions)) {
            $query->where(function ($q) use ($extensions) {
                foreach ($extensions as $ext) {
                    $q->orWhere('file_name', 'LIKE', "%.{$ext}");
                }
            });
        }

        if ($type === 'files') {
            $query->where('file_path', 'NOT LIKE', '%/BIOS/%');
        } elseif ($type === 'bios') {
            $query->where('file_path', 'LIKE', '%/BIOS/%');
        }

        return $query->get();
    }

    public function find(string $id): ?Game
    {
        return Game::find($id);
    }

    /**
     * Insert a game record or update
     */
    public function upsert(string $console, string $fileName, string $filePath, int $fileSize): void
    {
        $now = time();

        Game::upsert(
            [[
                'id'            => $console . ':' . $fileName,
                'console'       => $console,
                'file_name'     => $fileName,
                'file_path'     => $filePath,
                'file_size'     => $fileSize,
                'first_seen_at' => $now,
                'last_seen_at'  => $now,
            ]],
            uniqueBy: ['id'],
            update: ['file_size', 'last_seen_at'],
        );
    }
}
