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
     * Delete this console's rows that the scan just completed did not yield.
     * Returns the number of rows removed.
     *
     * Keyed on what the scan saw rather than on whether the file still exists,
     * because those differ: a file the console has since stopped accepting —
     * newly listed in `exclude_files`, or an extension dropped from the config —
     * is still on disk, and an existence check kept it in the library forever.
     *
     * The scan refreshes file_path first, so a file moved between subfolders
     * keeps its id and is not mistaken for a removed one. Metadata is keyed by
     * md5 and deliberately left alone — it re-associates if the file returns.
     *
     * @param string[] $scannedIds Ids yielded by the scan, from scanUpsert().
     */
    public function pruneExcept(Console $console, array $scannedIds): int
    {
        $keep = array_flip($scannedIds);

        $stale = Game::query()
            ->where('console', $console->key)
            ->pluck('id')
            ->reject(fn(string $id) => isset($keep[$id]));

        // Chunked so a large library cannot exceed SQLite's bound-variable limit.
        return (int) $stale
            ->chunk(500)
            ->sum(fn(Collection $chunk) => Game::whereIn('id', $chunk->values()->all())->delete());
    }

    /**
     * Game + BIOS counts per console, keyed by console slug.
     *
     * Aggregated in SQL rather than by hydrating every row: the dashboard and the
     * console list both call this on every render.
     *
     * The metadata join is one row at most per game — game_metadata is keyed by
     * md5 — so it cannot inflate the counts.
     *
     * @return Collection<string, array{game_count: int, bios_count: int, identified_count: int, bytes: int}>
     */
    public function consoleCounts(): Collection
    {
        $biosMatch = "games.file_path LIKE '%" . Game::BIOS_SEGMENT . "%'";
        $isGame    = "CASE WHEN {$biosMatch} THEN 0 ELSE 1 END";

        return Game::query()
            ->leftJoin('game_metadata', 'games.file_md5', '=', 'game_metadata.md5')
            ->selectRaw('games.console AS console')
            ->selectRaw("SUM({$isGame}) AS game_count")
            ->selectRaw("SUM(CASE WHEN {$biosMatch} THEN 1 ELSE 0 END) AS bios_count")
            ->selectRaw("SUM(CASE WHEN game_metadata.md5 IS NULL THEN 0 ELSE {$isGame} END) AS identified_count")
            ->selectRaw('COALESCE(SUM(games.file_size), 0) AS total_bytes')
            ->groupBy('games.console')
            ->get()
            ->keyBy('console')
            ->map(fn(Game $row) => [
                'game_count'       => (int) $row->game_count,
                'bios_count'       => (int) $row->bios_count,
                'identified_count' => (int) $row->identified_count,
                'bytes'            => (int) $row->total_bytes,
            ]);
    }

    /**
     * Library totals in one query: games and BIOS images counted separately, and
     * the bytes they occupy together.
     *
     * BIOS files are library rows but not games, so they are split out with the
     * same predicate consoleCounts() uses. `bytes` deliberately covers both —
     * it reports disk occupied, and a BIOS image occupies disk.
     *
     * @return array{game_count: int, bios_count: int, bytes: int}
     */
    public function librarySummary(): array
    {
        $biosMatch = "file_path LIKE '%" . Game::BIOS_SEGMENT . "%'";

        $row = Game::query()
            ->selectRaw("SUM(CASE WHEN {$biosMatch} THEN 0 ELSE 1 END) AS game_count")
            ->selectRaw("SUM(CASE WHEN {$biosMatch} THEN 1 ELSE 0 END) AS bios_count")
            ->selectRaw('COALESCE(SUM(file_size), 0) AS total_bytes')
            ->first();

        return [
            'game_count' => (int) ($row->game_count ?? 0),
            'bios_count' => (int) ($row->bios_count ?? 0),
            'bytes'      => (int) ($row->total_bytes ?? 0),
        ];
    }

    /**
     * The most recently added games, newest first.
     *
     * `first_seen_at` is only written on insert, so a rescan does not reshuffle
     * this list.
     *
     * @return EloquentCollection<int, Game>
     */
    public function recentlyAdded(int $limit): EloquentCollection
    {
        return Game::query()
            ->with('metadata')
            ->whereRaw($this->gameOnly())
            ->orderByDesc('first_seen_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Games with no provider metadata, newest first.
     *
     * A file that was never hashed cannot have metadata, so a null md5 counts as
     * unidentified alongside a hash nothing has matched yet.
     *
     * @return EloquentCollection<int, Game>
     */
    public function unidentified(int $limit): EloquentCollection
    {
        return $this->unidentifiedQuery()
            ->orderByDesc('first_seen_at')
            ->limit($limit)
            ->get();
    }

    /**
     * How much of the library carries provider metadata.
     *
     * Kept apart from librarySummary(), which the login page and the sidebar run
     * on every render and which does not need the join.
     *
     * @return array{identified: int, unidentified: int}
     */
    public function identificationCounts(): array
    {
        $total       = (int) Game::query()->whereRaw($this->gameOnly())->count();
        $unidentified = (int) $this->unidentifiedQuery()->count();

        return [
            'identified'   => $total - $unidentified,
            'unidentified' => $unidentified,
        ];
    }

    /**
     * Games lacking metadata, without an order or a limit.
     *
     * @return Builder<Game>
     */
    private function unidentifiedQuery(): Builder
    {
        return Game::query()
            ->whereRaw($this->gameOnly())
            ->where(fn(Builder $query) => $query
                ->whereNull('file_md5')
                ->orWhereDoesntHave('metadata'));
    }

    /**
     * SQL excluding BIOS images, which are library rows but not games.
     */
    private function gameOnly(): string
    {
        return "file_path NOT LIKE '%" . Game::BIOS_SEGMENT . "%'";
    }

    /**
     * Record a scanned file without hashing it.
     *
     * Hashing a multi-GB ISO took longer than one request is allowed to run, so
     * a scan only indexes; hashPending() fills file_md5 in afterwards. A stored
     * hash survives only while file_size still matches — a changed size means
     * the old digest describes a file that is gone.
     *
     * @return string The row's id, which pruneExcept() needs to keep it.
     */
    public function scanUpsert(string $console, \SplFileInfo $file): string
    {
        $id       = (string) GameId::make($console, $file->getFilename());
        $existing = $this->find($id);
        $size     = $file->getSize();

        $md5 = ($existing && (int) $existing->file_size === $size) ? $existing->file_md5 : null;

        $this->upsert($console, $file->getFilename(), $file->getPathname(), $size, $md5);

        return $id;
    }

    /**
     * This console's games still awaiting a hash, oldest-seen first so repeated
     * batches walk the backlog instead of re-picking the same rows.
     *
     * @return EloquentCollection<int, Game>
     */
    public function awaitingHash(Console $console, int $limit): EloquentCollection
    {
        return $this->awaitingHashQuery($console)
            ->orderBy('first_seen_at')
            ->limit($limit)
            ->get();
    }

    /**
     * How many of this console's games still have no md5.
     */
    public function awaitingHashCount(Console $console): int
    {
        return (int) $this->awaitingHashQuery($console)->count();
    }

    /**
     * Store a digest computed outside the scan pass.
     */
    public function storeMd5(string $id, string $md5): void
    {
        Game::whereKey($id)->update(['file_md5' => $md5]);
    }

    /**
     * Unhashed rows for one console, without an order or a limit.
     *
     * @return Builder<Game>
     */
    private function awaitingHashQuery(Console $console): Builder
    {
        return Game::query()
            ->where('console', $console->key)
            ->whereNull('file_md5');
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
