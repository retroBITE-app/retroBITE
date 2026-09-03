<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FolderScope;
use App\Exceptions\ValidationException;
use App\Repositories\GameRepository;
use App\Support\Console;
use App\Support\PathRules;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Creates and removes the subfolders a console's library is organised into.
 */
class ConsoleFolderService
{
    public function __construct(
        private FilesystemService $filesystem,
        private GameRepository $games,
    ) {}

    /**
     * Create each requested folder. An empty value means the console root, which
     * is how a console gets installed in the first place.
     *
     * @param array<int, mixed> $subfolders
     * @throws ValidationException
     */
    public function create(Console $console, array $subfolders): void
    {
        $targets = Arr::map(
            $subfolders,
            fn(mixed $sub): string => PathRules::normalizeSubfolder((string) $sub),
        );

        foreach ($targets as $target) {
            if (!PathRules::isSubfolderOrRoot($target)) {
                throw ValidationException::because('Invalid subfolder: ' . $target);
            }
        }

        foreach ($targets as $target) {
            $this->filesystem->createDir($console, $target);
        }
    }

    /**
     * Create the root folder for each named console, so installing several is one
     * request rather than one per console.
     *
     * @param array<int, mixed> $consoleKeys
     * @return array<string, string> Console key => failure reason, for the ones that failed.
     */
    public function install(array $consoleKeys): array
    {
        $failures = [];

        foreach ($consoleKeys as $key) {
            $console = Console::tryFrom(is_scalar($key) ? (string) $key : null);

            if ($console === null) {
                $failures[(string) $key] = 'Unknown console';
                continue;
            }

            try {
                $this->filesystem->createDir($console, '');
            } catch (Throwable $e) {
                logger()->error('Could not install console', [
                    'console' => $console->key,
                    'message' => $e->getMessage(),
                ]);

                $failures[$console->key] = 'Could not create the directory';
            }
        }

        return $failures;
    }

    /**
     * Recursively delete a folder and then its game rows, returning how many rows
     * went. Disk first — dropping the rows first left them gone when the rmdir
     * failed.
     *
     * @throws ValidationException
     */
    public function delete(Console $console, string $folder): int
    {
        $folder = PathRules::normalizeSubfolder($folder);

        if ($folder === FolderScope::Root->value || !PathRules::isSubfolder($folder)) {
            throw ValidationException::because('Invalid folder');
        }

        if (!$this->filesystem->deleteDir($console, $folder)) {
            throw ValidationException::because('Folder not found or could not be deleted');
        }

        return $this->games->deleteByFolder($console, $folder);
    }
}
