<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LibraryFileRejection;
use App\Enums\TransferFailure;
use App\Enums\TransferMode;
use App\Exceptions\LibraryFileRejected;
use App\Exceptions\LibraryPathException;
use App\Exceptions\TransferFailed;
use App\Jobs\FileTransferJob;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\GameFile;
use App\Support\Console;
use App\Support\LibraryPath;
use App\Support\Scanning\FolderCounts;
use App\Transfers\FileTransfer;
use App\Transfers\Location;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Moving a game between its layout's folders, and deleting one of its files.
 *
 * The only changes to somebody's library the game page makes. A move goes
 * through FileTransferJob and a delete through LibraryPath, and the database is changed to match rather than
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
     * failure part way through moves the ones already moved back. Renames,
     * so it runs in the request rather than on the queue.
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
        $moves = $this->movesFor($console, $plan);

        $this->transfer($moves);

        try {
            DB::transaction(function () use ($plan): void {
                foreach ($plan as ['file' => $file, 'path' => $path]) {
                    $file->update(['path' => $path]);
                }
            });
        } catch (Throwable $e) {
            Log::error('Moved files could not be recorded.', ['game' => $game->id, 'exception' => $e::class]);

            $this->moveBack($moves);

            throw LibraryFileRejected::because(LibraryFileRejection::Unwritable);
        }

        FolderCounts::recount($console);
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
            FolderCounts::recount($console);
        }
    }

    /**
     * Rename files in place, each within the folder it is in, and record the
     * new names.
     *
     * Unlike a move, one clash does not stop the rest: a batch spans a whole
     * console, and a single name already taken must not hold back hundreds of
     * others. A file whose new name is taken, whose file is gone, or which is
     * outside the console's folder is left as it is and not counted. A
     * failure while renaming undoes every rename made.
     *
     * @param  array<int, array{file: GameFile, to: string}>  $renames  the new filename, not a path
     * @return int how many were renamed
     *
     * @throws LibraryFileRejected
     */
    public function renameFiles(Console $console, array $renames): int
    {
        if (! ConsoleSourceFolder::has($console)) {
            throw LibraryFileRejected::because(LibraryFileRejection::Unconfigured);
        }

        $plan = $this->planRename($console, $renames);

        if ($plan === []) {
            return 0;
        }

        $moves = $this->movesFor($console, $plan);

        $this->transfer($moves);

        try {
            DB::transaction(function () use ($plan): void {
                foreach ($plan as ['file' => $file, 'to' => $to, 'path' => $path]) {
                    $file->update(['path' => $path, 'filename' => basename($to)]);
                }
            });
        } catch (Throwable $e) {
            Log::error('Renamed files could not be recorded.', ['console' => $console->key, 'exception' => $e::class]);

            $this->moveBack($moves);

            throw LibraryFileRejected::because(LibraryFileRejection::Unwritable);
        }

        return count($plan);
    }

    /**
     * File games that sit loose in the console's folder into folders of their
     * own, every file of each — a cue with its bins, a playlist and its discs —
     * and record where they went.
     *
     * A game already in a folder is left where it is; so is one with a file
     * missing, or whose folder would clash with something already there. A
     * sheet names its files relative to itself, and the set moves together,
     * so it still reads. A failure while moving puts back every file moved.
     *
     * @param  array<int, array{game: Game, folder: string}>  $moves  the folder is a name, not a path
     * @return int how many games were moved
     *
     * @throws LibraryFileRejected
     */
    public function organize(Console $console, array $moves): int
    {
        if (! ConsoleSourceFolder::has($console)) {
            throw LibraryFileRejected::because(LibraryFileRejection::Unconfigured);
        }

        $root = (string) ConsoleSourceFolder::pathFor($console);
        $plan = [];
        $games = 0;
        $taken = [];

        foreach ($moves as ['game' => $game, 'folder' => $folder]) {
            $entries = $this->planOrganize($console, $game, $folder, $root, $taken);

            if ($entries === []) {
                continue;
            }

            foreach ($entries as $entry) {
                $taken[Arr::get($entry, 'to')] = true;
            }

            $plan = [...$plan, ...$entries];
            $games++;
        }

        if ($plan === []) {
            return 0;
        }

        $moves = $this->movesFor($console, $plan);

        $this->transfer($moves);

        try {
            DB::transaction(function () use ($plan): void {
                foreach ($plan as ['file' => $file, 'path' => $path]) {
                    $file->update(['path' => $path]);
                }
            });
        } catch (Throwable $e) {
            Log::error('Organized files could not be recorded.', ['console' => $console->key, 'exception' => $e::class]);

            $this->moveBack($moves);

            throw LibraryFileRejected::because(LibraryFileRejection::Unwritable);
        }

        FolderCounts::recount($console);

        return $games;
    }

    /**
     * Where one game's files go, or nothing when the game is not one to move.
     *
     * @param  array<string, bool>  $taken  targets earlier games in this batch claimed
     * @return array<int, array{file: GameFile, from: string, to: string, path: string}>
     */
    private function planOrganize(Console $console, Game $game, string $folder, string $root, array $taken): array
    {
        // A name, never a path: nothing the caller passes can move files out
        // of the console's folder or into somebody else's.
        if ($folder === '' || $folder !== basename($folder) || in_array($folder, ['.', '..'], true)) {
            return [];
        }

        $entries = [];

        foreach ($game->files as $file) {
            $from = $this->consoleRelative($console, $file);

            // Loose at the top only: a game already in a folder is filed.
            if ($from === null || Str::contains($from, '/')) {
                return [];
            }

            if (! $file->isPresent() || ! $this->onDisk($console, $from)) {
                return [];
            }

            $to = $folder.'/'.$file->filename;

            if (array_key_exists($to, $taken) || $this->onDisk($console, $to)) {
                return [];
            }

            $entries[] = ['file' => $file, 'from' => $from, 'to' => $to, 'path' => $root.'/'.$to];
        }

        // A row for a file that has gone missing can still hold the name.
        if ($entries !== [] && GameFile::query()->whereIn('path', array_column($entries, 'path'))->exists()) {
            return [];
        }

        return $entries;
    }

    /**
     * Where each file goes, checked against the disk and the database first.
     *
     * @return list<array{file: GameFile, from: string, to: string, path: string}>
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
     * Where each renamed file goes, leaving out any that cannot be renamed.
     *
     * @param  array<int, array{file: GameFile, to: string}>  $renames
     * @return list<array{file: GameFile, from: string, to: string, path: string}>
     *
     * @throws LibraryFileRejected
     */
    private function planRename(Console $console, array $renames): array
    {
        $root = (string) ConsoleSourceFolder::pathFor($console);
        $plan = [];
        $taken = [];

        foreach ($renames as ['file' => $file, 'to' => $name]) {
            $from = $this->consoleRelative($console, $file);

            // A new name, not a path: anything carrying a separator is refused
            // here, so a rename can never be a move out of the folder.
            if ($from === null || $name === '' || $name !== basename($name)) {
                continue;
            }

            $folder = dirname($from);
            $to = $folder === '.' ? $name : $folder.'/'.$name;

            if ($to === $from || ! $file->isPresent() || ! $this->onDisk($console, $from)) {
                continue;
            }

            if (array_key_exists($to, $taken) || $this->onDisk($console, $to)) {
                continue;
            }

            $taken[$to] = true;
            $plan[] = ['file' => $file, 'from' => $from, 'to' => $to, 'path' => $root.'/'.$to];
        }

        // A row for a file that has gone missing can still hold the name.
        $held = GameFile::query()->whereIn('path', array_column($plan, 'path'))->pluck('path')->all();

        return array_values(array_filter($plan, function (array $entry) use ($held): bool {
            return ! in_array(Arr::get($entry, 'path'), $held, true);
        }));
    }

    /**
     * A plan's steps as moves inside the console's folder, for FileTransferJob.
     *
     * @param  array<int, array{from: string, to: string}>  $plan
     * @return list<FileTransfer>
     */
    private function movesFor(Console $console, array $plan): array
    {
        return array_values(array_map(
            fn (array $step): FileTransfer => new FileTransfer(Location::library($console, $step['from']), Location::library($console, $step['to'])),
            $plan,
        ));
    }

    /**
     * Move every planned file, moving them back if one of them fails.
     *
     * @param  list<FileTransfer>  $moves
     *
     * @throws LibraryFileRejected
     */
    private function transfer(array $moves): void
    {
        try {
            FileTransferJob::now($moves, TransferMode::Move);
        } catch (TransferFailed $e) {
            throw LibraryFileRejected::because(match ($e->reason) {
                TransferFailure::Exists => LibraryFileRejection::Exists,
                TransferFailure::SourceMissing => LibraryFileRejection::Missing,
                default => LibraryFileRejection::Unwritable,
            });
        }
    }

    /**
     * Undo a move whose rows could not be written. Logged rather than thrown:
     * the caller is already failing.
     *
     * @param  list<FileTransfer>  $moves
     */
    private function moveBack(array $moves): void
    {
        try {
            FileTransferJob::now(
                array_map(fn (FileTransfer $move): FileTransfer => new FileTransfer($move->to, $move->from), $moves),
                TransferMode::Move,
            );
        } catch (TransferFailed $e) {
            Log::error('A move could not be undone.', ['reason' => $e->reason->value]);
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
