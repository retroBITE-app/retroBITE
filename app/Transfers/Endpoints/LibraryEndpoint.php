<?php

declare(strict_types=1);

namespace App\Transfers\Endpoints;

use App\Enums\TransferFailure;
use App\Exceptions\LibraryPathException;
use App\Exceptions\TransferFailed;
use App\Support\Console;
use App\Support\LibraryPath;

/**
 * One console's folder in the library, through the gate.
 *
 * Every path is admitted by {@see LibraryPath}, so a transfer is held to the
 * same rules as every other write there: inside the console's folder, no dot
 * segments, no symbolic links. A move within the folder, or out of upload
 * staging, is a rename rather than a copy.
 */
final class LibraryEndpoint extends LocalEndpoint
{
    public function __construct(
        public readonly Console $console,
        private readonly LibraryPath $paths,
    ) {}

    public function canAdopt(Endpoint $from): bool
    {
        return ($from instanceof self && $from->console->key === $this->console->key)
            || $from instanceof StagingEndpoint;
    }

    public function adopt(Endpoint $from, string $fromPath, string $path): void
    {
        try {
            match (true) {
                $from instanceof self && $from->console->key === $this->console->key => $this->paths->relocate($this->console, $fromPath, $path),
                $from instanceof StagingEndpoint => $this->paths->moveInto($this->console, $path, $from->localFile($fromPath)),
                default => throw TransferFailed::because(TransferFailure::Rejected, $path),
            };
        } catch (LibraryPathException $e) {
            throw TransferFailed::because($e->reason === LibraryPathException::EXISTS
                ? TransferFailure::Exists
                : TransferFailure::Unwritable, $path, $e);
        }
    }

    public function delete(string $path): void
    {
        try {
            $this->paths->delete($this->console, $path);
        } catch (LibraryPathException $e) {
            throw TransferFailed::because(TransferFailure::Unwritable, $path, $e);
        }
    }

    protected function makeDirectory(string $directory): void
    {
        if ($directory === '') {
            return;
        }

        try {
            $this->paths->ensureDirectory($this->console, $directory);
        } catch (LibraryPathException $e) {
            throw TransferFailed::because(TransferFailure::Unwritable, $directory, $e);
        }
    }

    protected function absolute(string $path): string
    {
        try {
            return $this->paths->absolute($this->console, $path);
        } catch (LibraryPathException $e) {
            throw TransferFailed::because($e->reason === LibraryPathException::UNCONFIGURED
                ? TransferFailure::Unwritable
                : TransferFailure::Rejected, $path, $e);
        }
    }

    protected function writable(): bool
    {
        return true;
    }

    protected function root(): string
    {
        return (string) config('settings.games_path');
    }
}
