<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LibraryFileRejection;
use App\Exceptions\LibraryFileRejected;
use App\Exceptions\LibraryPathException;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\GameFile;
use App\Support\Console;
use App\Support\LibraryPath;
use App\Support\Scanning\FolderCounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Moving a game between its layout's folders, and deleting one of its files.
 *
 * The only changes to somebody's library the game page makes. Every write
 * goes through LibraryPath, and the database is changed to match rather than
 * left for the next scan to find: a moved game keeps its checksums, its match
 * and its artwork, which a rescan would have to rebuild from nothing.
 */
final class LibraryFiles
{
    public function __construct(private readonly LibraryPath $paths) {}

    /**
     * The layout folder every one of the game's files sits directly in.
     *
     * Null when they are split between folders, nested below one, or the game
     * has no files at all.
     */
    public function currentDestination(Game $game): ?string
    {
        $console = $game->console();

        if ($console === null || $game->files->isEmpty()) {
            return null;
        }

        $folders = $game->files
            ->map(function (GameFile $file) use ($console): ?string {
                $relative = $this->consoleRelative($console, $file);

                if ($relative === null) {
                    return null;
                }

                $directory = dirname($relative);

                return $directory === '.' ? '' : $directory;
            })
            ->unique()
            ->values();

        $folder = $folders->first();

        if ($folders->count() !== 1 || ! is_string($folder)) {
            return null;
        }

        return array_key_exists($folder, ConsoleSourceFolder::destinationsFor($console)) ? $folder : null;
    }

    /**
     * The folders this game could move to, as folder => label.
     *
     * @return array<string, string>
     */
    public function moveTargets(Game $game): array
    {
        $console = $game->console();

        if ($console === null || $game->files->isEmpty() || ! ConsoleSourceFolder::has($console)) {
            return [];
        }

        $current = $this->currentDestination($game);

        return collect(ConsoleSourceFolder::destinationsFor($console))
            ->reject(function (string $label, string $folder) use ($current): bool {
                return $folder === $current;
            })
            ->all();
    }

    /**
     * Move every file of a game into another of its layout's folders.
     *
     * All or nothing: every check is made before the first file moves, and a
     * failure part way through moves the ones already moved back.
     *
     * @throws LibraryFileRejected
     */
    public function moveGame(Game $game, string $destination): void
    {
        $console = $game->console();

        if ($console === null || ! ConsoleSourceFolder::has($console)) {
            throw LibraryFileRejected::because(LibraryFileRejection::Unconfigured);
        }

        if ($destination === $this->currentDestination($game)) {
            throw LibraryFileRejected::because(LibraryFileRejection::SameFolder);
        }

        if (! array_key_exists($destination, $this->moveTargets($game))) {
            throw LibraryFileRejected::because(LibraryFileRejection::Destination);
        }

        $plan = $this->planMove($console, $game, $destination);
        $moved = $this->relocateAll($console, $plan);

        try {
            DB::transaction(function () use ($plan): void {
                foreach ($plan as ['file' => $file, 'path' => $path]) {
                    $file->update(['path' => $path]);
                }
            });
        } catch (Throwable $e) {
            Log::error('Moved files could not be recorded.', ['game' => $game->id, 'exception' => $e::class]);

            $this->rollBack($console, $moved);

            throw LibraryFileRejected::because(LibraryFileRejection::Unwritable);
        }

        FolderCounts::forget($console);
    }

    /**
     * Delete one of a game's files from disk, and its row.
     *
     * The game itself, its details and its artwork stay, even when this was
     * its last file. A file already gone from disk only loses its row.
     *
     * @throws LibraryFileRejected
     */
    public function deleteFile(Game $game, int $fileId): void
    {
        $file = $game->files()->whereKey($fileId)->first();

        if (! $file instanceof GameFile) {
            throw LibraryFileRejected::because(LibraryFileRejection::NotThisGame);
        }

        $console = $game->console();
        $absolute = rtrim((string) config('settings.games_path'), '/').'/'.$file->path;

        if (file_exists($absolute) || is_link($absolute)) {
            $this->deleteFromDisk($console, $file);
        }

        $file->delete();

        if ($console !== null) {
            FolderCounts::forget($console);
        }
    }

    /**
     * Where each file goes, checked against the disk and the database first.
     *
     * @return array<int, array{file: GameFile, from: string, to: string, path: string}>
     *
     * @throws LibraryFileRejected
     */
    private function planMove(Console $console, Game $game, string $destination): array
    {
        $layout = ConsoleSourceFolder::layoutFor($console);
        $root = (string) ConsoleSourceFolder::pathFor($console);
        $plan = [];
        $taken = [];

        foreach ($game->files as $file) {
            $from = $this->consoleRelative($console, $file);

            if ($from === null) {
                throw LibraryFileRejected::because(LibraryFileRejection::OutsideConsole);
            }

            if (! $file->isPresent() || ! $this->onDisk($console, $from)) {
                throw LibraryFileRejected::because(LibraryFileRejection::Missing);
            }

            $to = $destination === '' ? $file->filename : $destination.'/'.$file->filename;

            if (! $layout->accepts($to)) {
                throw LibraryFileRejected::because(LibraryFileRejection::Destination);
            }

            if (array_key_exists($to, $taken) || $this->onDisk($console, $to)) {
                throw LibraryFileRejected::because(LibraryFileRejection::Exists);
            }

            $taken[$to] = true;
            $plan[] = ['file' => $file, 'from' => $from, 'to' => $to, 'path' => $root.'/'.$to];
        }

        // A row for a file that has gone missing can still hold the name.
        if (GameFile::query()->whereIn('path', array_column($plan, 'path'))->exists()) {
            throw LibraryFileRejected::because(LibraryFileRejection::Exists);
        }

        return $plan;
    }

    /**
     * Move every planned file, moving them back if one of them fails.
     *
     * @param  array<int, array{file: GameFile, from: string, to: string, path: string}>  $plan
     * @return array<int, array{from: string, to: string}> the moves made, in order
     *
     * @throws LibraryFileRejected
     */
    private function relocateAll(Console $console, array $plan): array
    {
        $moved = [];

        foreach ($plan as ['from' => $from, 'to' => $to]) {
            try {
                $this->paths->relocate($console, $from, $to);
            } catch (LibraryPathException $e) {
                Log::warning('A game could not be moved.', ['console' => $console->key, 'reason' => $e->reason]);

                $this->rollBack($console, $moved);

                throw LibraryFileRejected::because($e->reason === LibraryPathException::EXISTS
                    ? LibraryFileRejection::Exists
                    : LibraryFileRejection::Unwritable);
            }

            $moved[] = ['from' => $from, 'to' => $to];
        }

        return $moved;
    }

    /**
     * Undo moves, last first. Logged rather than thrown: the caller is already failing.
     *
     * @param  array<int, array{from: string, to: string}>  $moved
     */
    private function rollBack(Console $console, array $moved): void
    {
        foreach (array_reverse($moved) as ['from' => $from, 'to' => $to]) {
            try {
                $this->paths->relocate($console, $to, $from);
            } catch (LibraryPathException $e) {
                Log::error('A move could not be undone.', ['console' => $console->key, 'reason' => $e->reason]);
            }
        }
    }

    /**
     * Delete a file through the gate.
     *
     * @throws LibraryFileRejected
     */
    private function deleteFromDisk(?Console $console, GameFile $file): void
    {
        $relative = $console !== null ? $this->consoleRelative($console, $file) : null;

        if ($console === null || $relative === null) {
            throw LibraryFileRejected::because(LibraryFileRejection::OutsideConsole);
        }

        try {
            $this->paths->delete($console, $relative);
        } catch (LibraryPathException $e) {
            Log::warning('A file could not be deleted.', ['console' => $console->key, 'reason' => $e->reason]);

            throw LibraryFileRejected::because($e->reason === LibraryPathException::UNCONFIGURED
                ? LibraryFileRejection::Unconfigured
                : LibraryFileRejection::Unwritable);
        }
    }

    /**
     * Whether a path inside the console's folder is taken, refusing to guess on a gate error.
     *
     * @throws LibraryFileRejected
     */
    private function onDisk(Console $console, string $relative): bool
    {
        try {
            return $this->paths->exists($console, $relative) || is_link($this->paths->absolute($console, $relative));
        } catch (LibraryPathException $e) {
            Log::warning('A game could not be moved.', ['console' => $console->key, 'reason' => $e->reason]);

            throw LibraryFileRejected::because(LibraryFileRejection::Unwritable);
        }
    }

    /**
     * A file's path as the console's folder sees it, or null when it is outside it.
     */
    private function consoleRelative(Console $console, GameFile $file): ?string
    {
        $root = ConsoleSourceFolder::pathFor($console);

        if ($root === null || ! Str::startsWith($file->path, $root.'/')) {
            return null;
        }

        return Str::after($file->path, $root.'/');
    }
}
