<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ValidationException;
use App\Http\Input;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * One chunk of an upload, read and validated at the request boundary rather than
 * as thirty lines of guards inside the controller.
 */
final readonly class UploadChunk
{
    private function __construct(
        public string $uploadId,
        public string $filename,
        public string $subfolder,
        public int $fileSize,
        public int $index,
        public int $totalChunks,
        public UploadedFileInterface $file,
    ) {}

    /**
     * Read a chunk out of a request.
     *
     * @throws ValidationException When the multipart part itself is absent.
     */
    public static function fromRequest(ServerRequestInterface $request): self
    {
        $input = Input::body($request);
        $file  = Arr::get($request->getUploadedFiles(), 'chunk');

        if (!$file instanceof UploadedFileInterface) {
            throw ValidationException::fields(['chunk' => 'Required']);
        }

        return new self(
            uploadId:    $input->string('upload_id'),
            filename:    basename($input->string('filename')),
            subfolder:   PathRules::normalizeSubfolder($input->string('subfolder')),
            fileSize:    $input->integer('file_size', -1),
            index:       $input->integer('chunk_index', -1),
            totalChunks: $input->integer('total_chunks', -1),
            file:        $file,
        );
    }

    /**
     * Is this the last chunk, the one that triggers assembly?
     */
    public function isFinal(): bool
    {
        return $this->index === $this->totalChunks - 1;
    }

    /**
     * Reject a chunk we will not act on.
     *
     * @throws ValidationException
     */
    public function assertAcceptableFor(Console $console): void
    {
        $this->assertIdentifiable();
        $this->assertNameAcceptableFor($console);
        $this->assertSequenceCoherent();
        $this->assertSizeWithinLimit();
    }

    /**
     * The upload id names a staging directory, so it must be a real UUID.
     */
    private function assertIdentifiable(): void
    {
        if (!PathRules::isUploadId($this->uploadId)) {
            throw ValidationException::fields(['upload_id' => 'Invalid']);
        }
    }

    /**
     * The filename and destination must be legal, and the extension one this
     * console accepts.
     */
    private function assertNameAcceptableFor(Console $console): void
    {
        if ($this->filename === '' || $this->filename === '.' || $this->filename === '..') {
            throw ValidationException::fields(['filename' => 'Invalid']);
        }

        if (!$console->hasExtension(strtolower(pathinfo($this->filename, PATHINFO_EXTENSION)))) {
            throw ValidationException::because('File type not allowed for this console');
        }

        if (!PathRules::isSubfolderOrRoot($this->subfolder)) {
            throw ValidationException::fields(['subfolder' => 'Invalid']);
        }
    }

    /**
     * The index must fall inside a sane chunk count.
     */
    private function assertSequenceCoherent(): void
    {
        if ($this->index < 0 || $this->totalChunks < 1 || $this->index >= $this->totalChunks) {
            throw ValidationException::because('Invalid chunk metadata');
        }
    }

    /**
     * nginx caps a single chunk, not the assembled file, so the total is capped here.
     */
    private function assertSizeWithinLimit(): void
    {
        if ($this->fileSize < 0) {
            throw ValidationException::fields(['file_size' => 'Invalid']);
        }

        if ($this->fileSize > (int) config('settings.upload_max_bytes')) {
            throw ValidationException::because('File exceeds the maximum upload size');
        }
    }
}
