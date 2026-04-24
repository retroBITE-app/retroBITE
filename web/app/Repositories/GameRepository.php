<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Game;
use App\Support\Console;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class GameRepository
{
    /**
     * Fetch games for a console, optionally scoped to one or more folders.
     *
     * $folders values:
     *   - []             → all games for the console
     *   - ['root', ...]  → games directly under games/{console.folder}/ (no subfolder) ∪ …
     *   - ['DVD', 'CD']  → union of games under games/{console.folder}/DVD/ and /CD/
     */
    public function allForConsoleFolders(Console $console, array $folders): EloquentCollection
    {
        $query = Game::with('metadata')->where('console', $console->key);

        if ($folders === []) {
            return $query->get();
        }

        $base = '/' . $console->folder . '/';

        $query->where(function ($q) use ($folders, $base) {
            foreach ($folders as $folder) {
                if ($folder === 'root') {
                    $q->orWhere('file_path', 'NOT LIKE', '%' . $base . '%/%');
                } else {
                    $q->orWhere('file_path', 'LIKE', '%' . $base . $folder . '/%');
                }
            }
        });

        return $query->get();
    }

    public function find(string $id): ?Game
    {
        return Game::with('metadata')->find($id);
    }

    /**
     * Delete all game rows whose file_path sits under games/{console.folder}/{folder}/.
     * Returns the number of rows removed.
     */
    public function deleteByFolder(Console $console, string $folder): int
    {
        if ($folder === '') {
            return 0;
        }

        $pattern = '%/' . $console->folder . '/' . $folder . '/%';

        return (int) Game::where('console', $console->key)
            ->where('file_path', 'LIKE', $pattern)
            ->delete();
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
