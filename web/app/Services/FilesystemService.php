<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PathOutsideConsoleException;
use App\Support\Console;
use App\Support\PathRules;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

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
     * The subfolder a file sits in, relative to the console root. Returns '' for a
     * file directly under the root, and may be nested (e.g. "BIOS/Misc BIOS").
     *
     * Paths that do not live under this console's root also yield '' — the move
     * guard rejects those separately rather than guessing at a folder for them.
     */
    public function relativeFolder(Console $console, ?string $filePath): string
    {
        $base = $console->path() . '/';

        if ($filePath === null || !Str::startsWith($filePath, $base)) {
            return '';
        }

        $relative = Str::after($filePath, $base);
        return Str::contains($relative, '/') ? Str::beforeLast($relative, '/') : '';
    }

    /**
     * Recursively scan a console's game directory and yield each valid SplFileInfo.
     * Symlinks are skipped — games/ is also writable over SMB and FTP, so a link
     * planted there would otherwise register a path outside the console root.
     *
     * @return iterable<\SplFileInfo>
     */
    public function scanConsoleDir(Console $console): iterable
    {
        $dir = $this->ensureDir($console->path());

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $dir,
                \RecursiveDirectoryIterator::SKIP_DOTS,
            ),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isLink() || !$file->isFile()) {
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
        if (!PathRules::isUploadId($uploadId)) {
            throw new RuntimeException('Invalid upload id');
        }

        $chunk->moveTo($this->ensureDir($this->stagingDir($uploadId)) . '/' . $chunkIndex . '.part');
    }

    /**
     * Absolute destination path for a file, creating the target directory if needed.
     */
    public function resolvePath(Console $console, string $filename, string $subfolder): string
    {
        $dir = $this->assertWithin($console, $console->path($subfolder), allowRoot: true);

        return $this->ensureDir($dir) . '/' . basename($filename);
    }

    /**
     * Create a console directory (and optional subfolder) on disk.
     */
    public function createDir(Console $console, string $subfolder): void
    {
        $this->ensureDir(
            $this->assertWithin($console, $console->path($subfolder), allowRoot: true)
        );
    }

    /**
     * Move a file into a different subfolder of the same console.
     */
    public function moveFile(Console $console, string $sourcePath, string $subfolder): string
    {
        $source = realpath($sourcePath) ?: null;

        if ($source === null || !is_file($source)) {
            throw new RuntimeException('Source file not found on disk');
        }

        $this->assertWithin($console, $source);

        $targetDir = $this->assertWithin($console, $console->path($subfolder), allowRoot: true);

        if (!is_dir($targetDir)) {
            throw new RuntimeException('Destination folder does not exist');
        }

        $destination = $targetDir . '/' . basename($source);

        if ($destination === $source) {
            throw new RuntimeException('Source and destination are the same');
        }

        if (file_exists($destination)) {
            throw new RuntimeException('A file with that name already exists in the destination');
        }

        if (!@rename($source, $destination)) {
            // error_get_last() carries absolute host paths, so it is logged, not thrown.
            logger()->error('rename() failed', [
                'source'      => $source,
                'destination' => $destination,
                'error'       => error_get_last()['message'] ?? 'unknown error',
            ]);

            throw new RuntimeException('Could not move the file');
        }

        return $destination;
    }

    /**
     * Does a file already sit at this console-relative location?
     */
    public function exists(Console $console, string $subfolder, string $filename): bool
    {
        return is_file($console->path($subfolder) . '/' . basename($filename));
    }

    /**
     * Delete one game file, reporting whether anything was removed. Refuses — and
     * logs — a path outside the console root rather than throwing, so a stale row
     * pointing elsewhere can still be cleaned up without unlinking that file.
     */
    public function deleteFile(Console $console, string $filePath): bool
    {
        $target = realpath($filePath) ?: null;

        if ($target === null || !is_file($target)) {
            return false;
        }

        try {
            $this->assertWithin($console, $target);
        } catch (RuntimeException $e) {
            logger()->warning('Refused to delete a file outside the console folder', [
                'console' => $console->key,
                'path'    => $target,
                'reason'  => $e->getMessage(),
            ]);

            return false;
        }

        return @unlink($target);
    }

    /**
     * Recursively delete a subfolder of a console. Refuses empty / root / paths
     * that escape the console root.
     */
    public function deleteDir(Console $console, string $subfolder): bool
    {
        $subfolder = PathRules::normalizeSubfolder($subfolder);

        if (!PathRules::isSubfolder($subfolder)) {
            return false;
        }

        $target = realpath($console->path($subfolder)) ?: null;

        if ($target === null || !is_dir($target)) {
            return false;
        }

        // Must live strictly under the console root, and not be the root itself.
        try {
            $this->assertWithin($console, $target);
        } catch (RuntimeException) {
            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($target, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $fsi) {
            /** @var \SplFileInfo $fsi */
            $fsi->isDir() && !$fsi->isLink()
                ? @rmdir($fsi->getPathname())
                : @unlink($fsi->getPathname());
        }

        return @rmdir($target);
    }

    /**
     * Reassemble ordered .part files into the destination path, then clean up the
     * temp dir. Verifies every chunk is present first, and refuses to overwrite.
     */
    public function assembleFile(string $uploadId, int $totalChunks, string $destPath): void
    {
        if (!PathRules::isUploadId($uploadId)) {
            throw new RuntimeException('Invalid upload id');
        }

        if (file_exists($destPath)) {
            throw new RuntimeException('A file with that name already exists');
        }

        $parts = $this->assertChunksPresent($uploadId, $totalChunks);

        $dest = @fopen($destPath, 'wb');

        if ($dest === false) {
            throw new RuntimeException('Could not open the destination file for writing');
        }

        try {
            foreach ($parts as $partPath) {
                $this->appendPart($partPath, $dest);
            }
        } catch (RuntimeException $e) {
            fclose($dest);
            @unlink($destPath);

            throw $e;
        }

        fclose($dest);

        $this->discardStaging($uploadId, $totalChunks);
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
            if (!PathRules::isUploadId($entry->getFilename())) {
                continue;
            }
            if (time() - $entry->getMTime() > $maxAge) {
                foreach (glob($entry->getPathname() . '/*.part') ?: [] as $part) {
                    @unlink($part);
                }
                @rmdir($entry->getPathname());
            }
        }
    }

    /**
     * Assert a path resolves inside the console's root, and return it resolved.
     * The single containment gate every write, move and delete goes through.
     *
     * Paths that do not exist yet are resolved through their nearest existing
     * ancestor, so a console root can still be created while `..` is rejected.
     */
    private function assertWithin(Console $console, string $path, bool $allowRoot = false): string
    {
        $base     = $this->resolveIntendedPath($console->path());
        $resolved = $this->resolveIntendedPath($path);

        if ($base === null || $resolved === null) {
            throw new RuntimeException('Path could not be resolved');
        }

        if ($resolved === $base) {
            if ($allowRoot) {
                return $resolved;
            }

            throw PathOutsideConsoleException::forPath('the console root');
        }

        if (!str_starts_with($resolved . '/', $base . '/')) {
            throw PathOutsideConsoleException::forPath($path);
        }

        return $resolved;
    }

    /**
     * Resolve a path that does not exist yet by resolving its nearest existing
     * ancestor, so containment can be checked before the directory is created.
     */
    private function resolveIntendedPath(string $path): ?string
    {
        $existing = realpath($path);

        if ($existing !== false) {
            return $existing;
        }

        $missing = [];
        $current = rtrim($path, '/');

        while ($current !== '' && $current !== '/' && !file_exists($current)) {
            $missing[] = basename($current);
            $current   = dirname($current);
        }

        $anchor = realpath($current) ?: null;

        if ($anchor === null) {
            return null;
        }

        return $missing === []
            ? $anchor
            : $anchor . '/' . implode('/', array_reverse($missing));
    }

    /**
     * Paths of every chunk, in order, failing if any is missing.
     *
     * @return string[]
     */
    private function assertChunksPresent(string $uploadId, int $totalChunks): array
    {
        $tmpDir = $this->stagingDir($uploadId);
        $parts  = [];

        for ($i = 0; $i < $totalChunks; $i++) {
            $path = $tmpDir . '/' . $i . '.part';

            if (!is_file($path)) {
                throw new RuntimeException("Upload is incomplete — chunk {$i} is missing");
            }

            $parts[] = $path;
        }

        return $parts;
    }

    /**
     * Append one chunk to the open destination handle.
     *
     * @param resource $dest
     */
    private function appendPart(string $partPath, $dest): void
    {
        $part = @fopen($partPath, 'rb');

        if ($part === false) {
            throw new RuntimeException('Could not read an uploaded chunk');
        }

        $copied = stream_copy_to_stream($part, $dest);
        fclose($part);

        if ($copied === false) {
            throw new RuntimeException('Could not append an uploaded chunk');
        }
    }

    /**
     * Remove an upload's staging directory and its chunks.
     */
    private function discardStaging(string $uploadId, int $totalChunks): void
    {
        $tmpDir = $this->stagingDir($uploadId);

        for ($i = 0; $i < $totalChunks; $i++) {
            @unlink($tmpDir . '/' . $i . '.part');
        }

        @rmdir($tmpDir);
    }

    /**
     * Temp directory holding one upload's chunks.
     */
    private function stagingDir(string $uploadId): string
    {
        return config('settings.tmp_path') . '/' . $uploadId;
    }

    /**
     * Create a directory if absent, failing loudly rather than returning a path
     * nothing can be written to.
     */
    private function ensureDir(string $dir): string
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create directory');
        }

        return $dir;
    }
}
