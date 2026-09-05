<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\UploadException;
use App\Exceptions\ValidationException;
use App\Repositories\GameRepository;
use App\Support\Console;
use App\Support\UploadChunk;

/**
 * Owns the chunked-upload flow: stage each chunk, then assemble, verify and
 * register the finished file.
 */
class ChunkedUploadService
{
    public function __construct(
        private FilesystemService $filesystem,
        private GameRepository $games,
    ) {}

    /**
     * Stage one chunk. Returns true once the final chunk has been assembled and
     * recorded, false while more chunks are still expected.
     *
     * @throws ValidationException|UploadException
     */
    public function accept(Console $console, UploadChunk $chunk): bool
    {
        $this->filesystem->purgeAbandonedUploads();

        $chunk->assertAcceptableFor($console);

        $this->filesystem->writeChunk($chunk->uploadId, $chunk->index, $chunk->file);

        if (!$chunk->isFinal()) {
            return false;
        }

        $this->register($console, $chunk);

        return true;
    }

    /**
     * Assemble the staged chunks and record the file, verifying that what landed
     * on disk is the size the client declared.
     *
     * @throws UploadException
     */
    private function register(Console $console, UploadChunk $chunk): void
    {
        $destination = $this->filesystem->prepareDestination($console, $chunk->filename, $chunk->subfolder);

        $this->filesystem->assembleFile($chunk->uploadId, $chunk->totalChunks, $destination);

        $actualSize = filesize($destination);

        if ($actualSize === false || $actualSize !== $chunk->fileSize) {
            $this->filesystem->deleteFile($console, $destination);

            throw UploadException::sizeMismatch($chunk->fileSize, (int) $actualSize);
        }

        // Left unhashed on purpose: a 60 GB upload cannot be digested inside the
        // request that finishes it. LibraryScanService::hashPending() picks the
        // row up, the same way it does for a freshly scanned file.
        $this->games->upsert($console->key, $chunk->filename, $destination, $actualSize);
    }
}
