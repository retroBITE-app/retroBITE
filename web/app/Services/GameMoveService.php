<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Exceptions\StorageException;
use App\Exceptions\ValidationException;
use App\Models\Game;
use App\Support\Console;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Moves a game between a console's subfolders, keeping disk and database in step.
 */
class GameMoveService
{
    public function __construct(
        private FilesystemService $filesystem,
    ) {}

    /**
     * Move a game into $subfolder and point its row at the new path.
     *
     * @throws ValidationException|ConflictException|StorageException
     */
    public function move(Console $console, Game $game, string $subfolder): void
    {
        $this->assertMovable($console, $game, $subfolder);

        $from = (string) $game->file_path;

        try {
            $to = $this->filesystem->moveFile($console, $from, $subfolder);
        } catch (Throwable $e) {
            logger()->error('Move failed', ['game' => $game->id, 'message' => $e->getMessage()]);

            throw StorageException::moveFailed();
        }

        $this->recordNewPath($game, $from, $to);
    }

    /**
     * Reject a move whose destination is unknown, unchanged, or already occupied.
     *
     * @throws ValidationException|ConflictException
     */
    private function assertMovable(Console $console, Game $game, string $subfolder): void
    {
        $allowed = Collection::make($this->filesystem->listSubfolders($console))->prepend('');

        if (!$allowed->contains($subfolder)) {
            throw ValidationException::because('Unknown destination folder');
        }

        if ($this->filesystem->relativeFolder($console, $game->file_path) === $subfolder) {
            throw ValidationException::because('Game is already in that folder');
        }

        if ($this->filesystem->exists($console, $subfolder, (string) $game->file_name)) {
            throw new ConflictException(
                'A file named "' . $game->file_name . '" already exists there'
            );
        }
    }

    /**
     * Update the row, undoing the disk move if that write fails.
     *
     * @throws StorageException
     */
    private function recordNewPath(Game $game, string $from, string $to): void
    {
        try {
            $game->update(['file_path' => $to]);

            return;
        } catch (Throwable $e) {
            $context = ['game' => $game->id, 'message' => $e->getMessage()];
        }

        if (@rename($to, $from)) {
            logger()->error('Move rolled back after a failed database update', $context);

            throw StorageException::moveFailed();
        }

        logger()->error('Move left disk and database out of sync', [
            ...$context,
            'row_path'    => $from,
            'actual_path' => $to,
        ]);

        throw StorageException::moveOutOfSync();
    }
}
