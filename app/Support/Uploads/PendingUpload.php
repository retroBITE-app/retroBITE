<?php

declare(strict_types=1);

namespace App\Support\Uploads;

use Illuminate\Support\Arr;

/**
 * One upload between its first chunk and its move into the library.
 *
 * Everything the server decided when the upload began, so no later request
 * gets to name the console, the folder or the file again.
 */
final readonly class PendingUpload
{
    /**
     * @param  string  $destination  relative to the console's folder, '' for its root
     * @param  int  $size  bytes the file was declared to hold
     * @param  string  $folder  the game's own folder under the destination, '' for none
     */
    public function __construct(
        public string $id,
        public int $userId,
        public string $console,
        public string $destination,
        public string $filename,
        public int $size,
        public string $folder = '',
    ) {}

    /**
     * Rebuild one from what the cache kept, or null for anything else.
     *
     * @param  mixed  $stored  whatever the cache handed back
     */
    public static function fromArray(mixed $stored): ?self
    {
        if (! is_array($stored) || ! Arr::has($stored, ['id', 'user_id', 'console', 'destination', 'filename', 'size'])) {
            return null;
        }

        return new self(
            (string) Arr::get($stored, 'id'),
            (int) Arr::get($stored, 'user_id'),
            (string) Arr::get($stored, 'console'),
            (string) Arr::get($stored, 'destination'),
            (string) Arr::get($stored, 'filename'),
            (int) Arr::get($stored, 'size'),
            // Absent on an upload begun before game folders existed.
            (string) Arr::get($stored, 'folder', ''),
        );
    }

    /**
     * The file's path relative to the console's folder.
     */
    public function relative(): string
    {
        $parts = array_filter([trim($this->destination, '/'), $this->folder, $this->filename], function (string $part): bool {
            return $part !== '';
        });

        return implode('/', $parts);
    }

    /**
     * @return array{id: string, user_id: int, console: string, destination: string, filename: string, size: int, folder: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'console' => $this->console,
            'destination' => $this->destination,
            'filename' => $this->filename,
            'size' => $this->size,
            'folder' => $this->folder,
        ];
    }
}
