<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Console;
use Illuminate\Support\Collection;
use Psr\Http\Message\UploadedFileInterface;

class FilesystemService
{
    /**
     * Direct subdirectory names under a console's root folder on disk.
     *
     * @return string[]
     */
    public function listSubfolders(Console $console): array
    {
        $base = $console->path();
        if (!is_dir($base)) {
            return [];
        }

        return Collection::make(scandir($base) ?: [])
            ->reject(fn(string $name) => $name === '.' || $name === '..' || !is_dir($base . '/' . $name))
            ->values()
            ->all();
    }

    /**
     * Recursively scan a console's game directory and yield each valid SplFileInfo.
     *
     * @return iterable<\SplFileInfo>
     */
    public function scanConsoleDir(Console $console): iterable
    {
        $dir = $this->ensureDir($console->path());

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            if (in_array($file->getFilename(), $console->excludeFiles, true)) {
                continue;
            }
            if (!$console->hasExtension($file->getExtension())) {
                continue;
            }

            yield $file;
        }
    }

    /**
     * Write an uploaded chunk to the temp staging area.
     */
    public function writeChunk(string $uploadId, int $chunkIndex, UploadedFileInterface $chunk): void
    {
        $tmpDir = $this->ensureDir(config('settings.tmp_path') . '/' . $uploadId);

        $chunk->moveTo($tmpDir . '/' . $chunkIndex . '.part');
    }

    /**
     * Resolve the final destination path for a file, creating the directory if needed.
     */
    public function resolvePath(Console $console, string $filename, string $subfolder): string
    {
        return $this->ensureDir($console->path($subfolder)) . '/' . $filename;
    }

    /**
     * Create a console directory (and optional subfolder) on disk.
     */
    public function createDir(Console $console, string $subfolder): void
    {
        $this->ensureDir($console->path($subfolder));
    }

    /**
     * Recursively delete a subfolder of a console. Refuses empty / root / paths
     * that escape the console root.
     */
    public function deleteDir(Console $console, string $subfolder): bool
    {
        $subfolder = trim($subfolder, '/');
        if ($subfolder === '' || $subfolder === '.' || $subfolder === '..') {
            return false;
        }

        $base   = realpath($console->path()) ?: null;
        $target = realpath($console->path($subfolder)) ?: null;

        if ($base === null || $target === null) {
            return false;
        }

        // Target must live strictly under $base, and must not be $base itself.
        if ($target === $base || !str_starts_with($target . '/', $base . '/')) {
            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($target, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $fsi) {
            /** @var \SplFileInfo $fsi */
            $fsi->isDir() ? @rmdir($fsi->getPathname()) : @unlink($fsi->getPathname());
        }

        return @rmdir($target);
    }

    /**
     * Reassemble ordered .part files into the destination path, then clean up the temp dir.
     */
    public function assembleFile(string $uploadId, int $totalChunks, string $destPath): void
    {
        $tmpDir = config('settings.tmp_path') . '/' . $uploadId;

        $dest = fopen($destPath, 'wb');
        for ($i = 0; $i < $totalChunks; $i++) {
            $part = fopen($tmpDir . '/' . $i . '.part', 'rb');
            stream_copy_to_stream($part, $dest);
            fclose($part);
        }
        fclose($dest);

        for ($i = 0; $i < $totalChunks; $i++) {
            @unlink($tmpDir . '/' . $i . '.part');
        }
        @rmdir($tmpDir);
    }

    /**
     * Extract region codes from a filename.
     */
    public function resolveRegion(string $filename): ?string
    {
        preg_match_all('/\(([^)]+)\)/', $filename, $matches);

        $codes = $matches[1] ?? [];

        return Collection::make(config('regions'))
            ->filter(fn($region) => Collection::make($codes)->intersect($region['codes'])->isNotEmpty())
            ->keys()
            ->first();
    }

    /**
     * Delete temp upload dirs older than $maxAge seconds.
     */
    public function purgeAbandonedUploads(int $maxAge = 21600): void
    {
        $tmpBase = config('settings.tmp_path');

        if (!is_dir($tmpBase)) {
            return;
        }

        foreach (new \DirectoryIterator($tmpBase) as $entry) {
            if (!$entry->isDir() || $entry->isDot()) {
                continue;
            }
            if (!preg_match('/^[0-9a-f\-]{36}$/', $entry->getFilename())) {
                continue;
            }
            if (time() - $entry->getMTime() > $maxAge) {
                foreach (glob($entry->getPathname() . '/*.part') as $part) {
                    @unlink($part);
                }
                @rmdir($entry->getPathname());
            }
        }
    }

    private function ensureDir(string $dir): string
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }
}
