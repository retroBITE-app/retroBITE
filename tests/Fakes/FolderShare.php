<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Enums\TransferFailure;
use App\Exceptions\TransferFailed;
use App\Transfers\Smb\RemoteShare;
use Illuminate\Support\Facades\File;

/** One share of a {@see FolderShareClient}: a folder. */
final class FolderShare implements RemoteShare
{
    public function __construct(
        private readonly string $root,
        private readonly bool $readonly,
        private readonly FolderShareClient $client,
    ) {}

    public function size(string $path): ?int
    {
        $absolute = $this->root.'/'.$path;

        clearstatcache(true, $absolute);

        if (is_dir($absolute)) {
            return -1;
        }

        return is_file($absolute) ? (int) filesize($absolute) : null;
    }

    public function names(string $directory): array
    {
        $absolute = $this->root.'/'.$directory;

        return is_dir($absolute) ? array_values(array_diff((array) scandir($absolute), ['.', '..'])) : [];
    }

    public function makeDirectory(string $path): void
    {
        $this->refuseIfReadonly($path);

        File::ensureDirectoryExists($this->root.'/'.$path);
    }

    public function put(string $localFile, string $path): void
    {
        $this->refuseIfReadonly($path);

        if ($this->client->failPutOn !== null && str_contains($path, $this->client->failPutOn)) {
            throw TransferFailed::because(TransferFailure::Unreachable, $path);
        }

        if (! is_dir(dirname($this->root.'/'.$path))) {
            throw TransferFailed::because(TransferFailure::Rejected, $path);
        }

        copy($localFile, $this->root.'/'.$path);
        $this->client->writes[] = $path;
    }

    public function rename(string $from, string $to): void
    {
        $this->refuseIfReadonly($to);

        if ($this->size($to) !== null) {
            throw TransferFailed::because(TransferFailure::Exists, $to);
        }

        rename($this->root.'/'.$from, $this->root.'/'.$to);
    }

    public function delete(string $path): void
    {
        $this->refuseIfReadonly($path);

        File::delete($this->root.'/'.$path);
    }

    public function get(string $path): ?string
    {
        return is_file($this->root.'/'.$path) ? (string) file_get_contents($this->root.'/'.$path) : null;
    }

    public function write(string $path, string $contents): void
    {
        $this->refuseIfReadonly($path);

        file_put_contents($this->root.'/'.$path, $contents);
        $this->client->writes[] = $path;
    }

    private function refuseIfReadonly(string $path): void
    {
        if ($this->readonly) {
            throw TransferFailed::because(TransferFailure::Unwritable, $path);
        }
    }
}
