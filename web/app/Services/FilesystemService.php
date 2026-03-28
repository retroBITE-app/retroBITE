<?php

declare(strict_types=1);

namespace App\Services;

use Psr\Http\Message\UploadedFileInterface;

class FilesystemService
{
    /**
     * Recursively scan a console's game directory and yield each valid SplFileInfo
     */
    public function scanConsoleDir(string $console): iterable
    {
        $dir          = config('settings.games_path') . '/' . config("consoles.{$console}.folder");
        $extensions   = array_merge(
            config("consoles.{$console}.file_extensions", []),
            config("consoles.{$console}.bios_extensions", [])
        );
        $excludeFiles = config("consoles.{$console}.exclude_files", []);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            if (in_array($file->getFilename(), $excludeFiles, true)) {
                continue;
            }
            if (!in_array(strtolower($file->getExtension()), $extensions, true)) {
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
        $tmpDir = config('settings.tmp_path') . '/' . $uploadId;

        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $chunk->moveTo($tmpDir . '/' . $chunkIndex . '.part');
    }

    /**
     * Resolve the final destination path for a file, creating the directory if needed.
     */
    public function resolvePath(string $console, string $filename, string $subfolder): string
    {
        $gamesPath = config('settings.games_path');
        $folder    = config("consoles.{$console}.folder");

        $dir = $gamesPath . '/' . $folder . ($subfolder !== '' ? '/' . $subfolder : '');

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir . '/' . $filename;
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
     * Extract region codes from a filename
     */
    public function resolveRegion(string $filename): ?string
    {
        preg_match_all('/\(([^)]+)\)/', $filename, $matches);

        $codes = $matches[1] ?? [];

        return collect(config('regions'))
            ->filter(fn($region) => collect($codes)->intersect($region['codes'])->isNotEmpty())
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
}
