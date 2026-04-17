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
        $query = Game::with('metadata')
            ->where('console', $console)
            ->havingExtensions($extensions);

        return match ($type) {
            'files' => $query->games()->get(),
            'bios'  => $query->bios()->get(),
            default => $query->get(),
        };
    }

    public function find(string $id): ?Game
    {
        return Game::with('metadata')->find($id);
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
     * Upsert a scanned file, reusing the stored md5 when the file is unchanged.
     *
     * Hashing a multi-GB ISO is the slow part of scan; skipping it when
     * file_md5 is already set and file_size matches keeps repeat scans fast
     * and lets a second scan fill in anything a prior timeout missed.
     */
    public function scanUpsert(string $console, \SplFileInfo $file): void
    {
        $id       = $console . ':' . $file->getFilename();
        $existing = $this->find($id);
        $size     = $file->getSize();

        $md5 = ($existing && $existing->file_md5 !== null && (int) $existing->file_size === $size)
            ? $existing->file_md5
            : (md5_file($file->getPathname()) ?: null);

        $this->upsert($console, $file->getFilename(), $file->getPathname(), $size, $md5);
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
