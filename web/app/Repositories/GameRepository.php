<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\FolderScope;
use App\Models\Game;
use App\Support\Console;
use App\Support\GameId;
use App\Support\PathRules;
use Illuminate\Database\Eloquent\Builder;
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

        $folders = $this->legalFolders($folders);

        // A filter was asked for and nothing in it was usable, so nothing matches.
        // Skipping the condition instead would silently return the whole library.
        if ($folders === []) {
            return new EloquentCollection();
        }

        $query->where(function (Builder $scoped) use ($console, $folders) {
            foreach ($folders as $folder) {
                $this->scopeToFolder($scoped, $console, $folder);
            }
        });

        return $query->get();
    }

    /**
     * Filter pills for the console page: All, the root, then each subfolder, each
     * with its game count.
     *
     * @param string[] $subfolders
     * @return array<int, array{value: string, label: string, count: int}>
     */
    public function folderCounts(Console $console, array $subfolders): array
    {
        $pills = [
            [
                'value' => FolderScope::All->value,
                'label' => FolderScope::All->label(),
                'count' => $this->countForFolder($console, FolderScope::All->value),
            ],
            [
                'value' => FolderScope::Root->value,
                'label' => $console->folder,
                'count' => $this->countForFolder($console, FolderScope::Root->value),
            ],
        ];

        foreach ($subfolders as $sub) {
            $pills[] = [
                'value' => $sub,
                'label' => $sub,
                'count' => $this->countForFolder($console, $sub),
            ];
        }

        return $pills;
    }

    /**
     * One game by its composite id, with metadata eager-loaded.
     */
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

        if ($this->legalFolders([$folder]) === []) {
            return 0;
        }

        return (int) Game::where('console', $console->key)
            ->where('file_path', 'LIKE', $this->folderPattern($console, $folder))
            ->delete();
    }

    /**
     * Rows matching one folder value, counted in the database rather than by
     * re-filtering a full table read once per subfolder.
     */
    private function countForFolder(Console $console, string $folder): int
    {
        $query = Game::where('console', $console->key);

        if ($folder !== FolderScope::All->value && $this->legalFolders([$folder]) === []) {
            return 0;
        }

        if ($folder !== FolderScope::All->value) {
            // Grouped: scopeToFolder adds an orWhere, which at the top level would
            // widen the query past the console filter instead of narrowing it.
            $query->where(fn(Builder $scoped) => $this->scopeToFolder($scoped, $console, $folder));
        }

        return $query->count();
    }

    /**
     * Keep only folder values safe to interpolate into a LIKE pattern.
     *
     * SQLite's LIKE takes no escape character by default, so a value of "%" would
     * otherwise widen the filter to the whole library. PathRules' alphabet has no
     * wildcards, which makes validation the defence.
     *
     * @param array<int, mixed> $folders
     * @return string[]
     */
    private function legalFolders(array $folders): array
    {
        return array_values(array_filter(
            array_map(fn(mixed $folder): string => (string) $folder, $folders),
            fn(string $folder): bool => $folder === FolderScope::Root->value
                || PathRules::isSubfolder($folder),
        ));
    }

    /**
     * Add one folder's condition to a query. `root` means "not in any subfolder";
     * anything else is a subfolder name. Values arrive checked by legalFolders().
     */
    private function scopeToFolder(Builder $query, Console $console, string $folder): void
    {
        if ($folder === FolderScope::Root->value) {
            $query->orWhere('file_path', 'NOT LIKE', '%/' . $console->folder . '/%/%');

            return;
        }

        $query->orWhere('file_path', 'LIKE', $this->folderPattern($console, $folder));
    }

    /**
     * LIKE pattern matching everything inside games/{console.folder}/{folder}/.
     */
    private function folderPattern(Console $console, string $folder): string
    {
        return '%/' . $console->folder . '/' . $folder . '/%';
    }

    /**
     * Delete this console's rows whose file is gone from disk. Returns the number
     * of rows removed.
     *
     * Meant to run right after a scan, which refreshes file_path first, so a file
     * moved outside the app gets its path corrected rather than mistaken for a
     * missing one. Metadata is keyed by md5 and deliberately left alone — it
     * re-associates on its own if the file ever comes back.
     */
    public function pruneMissing(Console $console): int
    {
        $missing = Game::select(['id', 'file_path'])
            ->where('console', $console->key)
            ->get()
            ->reject(fn(Game $game) => is_file((string) $game->file_path))
            ->pluck('id');

        // Chunked so a large library cannot exceed SQLite's bound-variable limit.
        return (int) $missing
            ->chunk(500)
            ->sum(fn(Collection $chunk) => Game::whereIn('id', $chunk->values()->all())->delete());
    }

    /**
     * Game + BIOS counts per console, keyed by console slug.
     *
     * Aggregated in SQL rather than by hydrating every row: the dashboard and the
     * console list both call this on every render.
     *
     * @return Collection<string, array{game_count: int, bios_count: int}>
     */
    public function consoleCounts(): Collection
    {
        $biosMatch = "file_path LIKE '%" . Game::BIOS_SEGMENT . "%'";

        return Game::query()
            ->selectRaw('console')
            ->selectRaw("SUM(CASE WHEN {$biosMatch} THEN 0 ELSE 1 END) AS game_count")
            ->selectRaw("SUM(CASE WHEN {$biosMatch} THEN 1 ELSE 0 END) AS bios_count")
            ->groupBy('console')
            ->get()
            ->keyBy('console')
            ->map(fn(Game $row) => [
                'game_count' => (int) $row->game_count,
                'bios_count' => (int) $row->bios_count,
            ]);
    }

    /**
     * Row count and total bytes across the whole library, in one query.
     *
     * @return array{count: int, bytes: int}
     */
    public function librarySummary(): array
    {
        $row = Game::query()
            ->selectRaw('COUNT(*) AS row_count')
            ->selectRaw('COALESCE(SUM(file_size), 0) AS total_bytes')
            ->first();

        return [
            'count' => (int) ($row->row_count ?? 0),
            'bytes' => (int) ($row->total_bytes ?? 0),
        ];
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
        $id       = (string) GameId::make($console, $file->getFilename());
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
                'id'            => (string) GameId::make($console, $fileName),
                'console'       => $console,
                'file_name'     => $fileName,
                'file_path'     => $filePath,
                'file_size'     => $fileSize,
                'file_md5'      => $fileMd5,
                'first_seen_at' => $now,
                'last_seen_at'  => $now,
            ]],
            uniqueBy: ['id'],
            update: ['file_path', 'file_size', 'file_md5', 'last_seen_at'],
        );
    }
}
