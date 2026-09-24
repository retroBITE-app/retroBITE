<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UploadRejection;
use App\Exceptions\LibraryPathException;
use App\Exceptions\UploadRejected;
use App\Models\ConsoleSourceFolder;
use App\Support\Console;
use App\Support\LibraryPath;
use App\Support\Scanning\FolderCounts;
use App\Support\Uploads\PendingUpload;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SplFileInfo;

/**
 * ROM uploads, in chunks, into wherever a console's layout reads games from.
 *
 * Chunked because a PS2 or Wii image runs to several gigabytes and no single
 * request should carry that. Each upload is assembled in the library's staging
 * directory and moved into place through LibraryPath once the last byte is in.
 * Everything that decides where the file goes is settled in begin(), so the
 * chunks and the finish only ever name the upload.
 */
final class RomUploads
{
    /**
     * The largest body one chunk request may carry.
     *
     * Half the 256M request cap PHP and nginx are set to, which leaves room
     * for headers and for a proxy that counts differently.
     */
    public const CHUNK_BYTES = 128 * 1024 * 1024;

    /** Staged files and their state are given up after this long without a finish. */
    private const TTL_HOURS = 24;

    /** The longest name ext4 and most other filesystems take for one entry. */
    private const MAX_NAME_BYTES = 255;

    public function __construct(private readonly LibraryPath $paths) {}

    /**
     * Accept an upload before a byte of it is written, and open its staging file.
     *
     * @param  int  $size  bytes the browser says the file holds
     * @param  string  $destination  one of ConsoleSourceFolder::destinationsFor()' keys
     *
     * @throws UploadRejected
     */
    public function begin(Console $console, int $userId, string $filename, int $size, string $destination): PendingUpload
    {
        $this->assertAcceptable($console, $filename, $size, $destination);

        $upload = new PendingUpload((string) Str::uuid(), $userId, $console->key, $destination, $filename, $size);

        try {
            $this->assertRoomFor($console, $upload);

            $this->prune();

            if (! touch($this->partFor($upload->id))) {
                throw UploadRejected::because(UploadRejection::Unwritable);
            }
        } catch (LibraryPathException $e) {
            Log::warning('Upload refused by the library gate.', ['console' => $console->key, 'reason' => $e->reason]);

            throw UploadRejected::because(UploadRejection::Unwritable);
        }

        Cache::put(self::cacheKey($upload->id), $upload->toArray(), now()->addHours(self::TTL_HOURS));

        return $upload;
    }

    /**
     * Append one chunk to a staged upload. Returns the bytes staged so far.
     *
     * The offset must be the end of what is already staged, so a retried chunk
     * that did land is answered with where to carry on rather than written
     * twice.
     *
     * @param  resource  $stream  the request body
     *
     * @throws UploadRejected
     */
    public function append(string $id, int $userId, int $offset, $stream): int
    {
        $upload = $this->pending($id, $userId);
        $part = $this->stagedPart($upload);
        $received = (int) filesize($part);

        if ($offset !== $received) {
            throw UploadRejected::outOfOrder($received);
        }

        $room = $upload->size - $received;

        if ($room <= 0) {
            throw UploadRejected::because(UploadRejection::Overflow);
        }

        $written = $this->write($part, $stream, min($room, self::CHUNK_BYTES));

        // Anything still readable past what fits is more than the file was
        // declared to hold, and the whole chunk goes rather than half of it.
        $extra = fread($stream, 1);

        if ($extra !== false && $extra !== '') {
            $this->truncate($part, $received);

            throw UploadRejected::because(UploadRejection::Overflow);
        }

        return $received + $written;
    }

    /**
     * Move a complete upload into the console's folder.
     *
     * Returns the file's path relative to the library root, the form
     * game_files.path stores. Any refusal here gives the upload up: a file
     * that cannot be placed now will not be placed by asking again.
     *
     * @throws UploadRejected
     */
    public function finish(string $id, int $userId): string
    {
        $upload = $this->pending($id, $userId);
        $part = $this->stagedPart($upload);
        $console = Console::tryFrom($upload->console);

        if ((int) filesize($part) !== $upload->size) {
            throw UploadRejected::because(UploadRejection::Incomplete);
        }

        if ($console === null) {
            $this->discard($upload->id);

            throw UploadRejected::because(UploadRejection::Unknown);
        }

        try {
            $path = $this->paths->moveInto($console, $upload->relative(), $part);
        } catch (LibraryPathException $e) {
            Log::warning('Upload could not be placed.', ['console' => $console->key, 'reason' => $e->reason]);

            $this->discard($upload->id);

            throw UploadRejected::because($e->reason === LibraryPathException::EXISTS
                ? UploadRejection::Exists
                : UploadRejection::Unwritable);
        }

        Cache::forget(self::cacheKey($upload->id));

        // The folder has a file it did not have, so the cards' cached count
        // is the older answer.
        FolderCounts::forget($console);

        return $path;
    }

    /**
     * Give an upload up, staged bytes and all. Quiet about one it cannot find.
     */
    public function abandon(string $id, int $userId): void
    {
        try {
            $upload = $this->pending($id, $userId);
        } catch (UploadRejected) {
            return;
        }

        $this->discard($upload->id);
    }

    /**
     * Every check that can be made from the request alone, cheapest first.
     *
     * @throws UploadRejected
     */
    private function assertAcceptable(Console $console, string $filename, int $size, string $destination): void
    {
        if (! ConsoleSourceFolder::has($console)) {
            throw UploadRejected::because(UploadRejection::Unconfigured);
        }

        if (! in_array($destination, array_keys(ConsoleSourceFolder::destinationsFor($console)), true)) {
            throw UploadRejected::because(UploadRejection::Destination);
        }

        if (! self::isPlainName($filename)) {
            throw UploadRejected::because(UploadRejection::BadName);
        }

        // The scanner's own two tests, so an accepted file is one it will list.
        if (! $console->playsExtension(pathinfo($filename, PATHINFO_EXTENSION))) {
            throw UploadRejected::because(UploadRejection::WrongType);
        }

        if (in_array(Str::lower($filename), array_map('strtolower', $console->excludeFiles), true)) {
            throw UploadRejected::because(UploadRejection::Excluded);
        }

        if ($size < 1) {
            throw UploadRejected::because(UploadRejection::EmptyFile);
        }
    }

    /**
     * The checks that need the disk: the layout, a clash, and free space.
     *
     * @throws UploadRejected
     * @throws LibraryPathException
     */
    private function assertRoomFor(Console $console, PendingUpload $upload): void
    {
        if (! ConsoleSourceFolder::layoutFor($console)->accepts($upload->relative())) {
            throw UploadRejected::because(UploadRejection::Destination);
        }

        if ($this->paths->exists($console, $upload->relative())) {
            throw UploadRejected::because(UploadRejection::Exists);
        }

        $free = @disk_free_space($this->paths->stagingDirectory());

        if ($free !== false && $free < $upload->size) {
            throw UploadRejected::because(UploadRejection::NoSpace);
        }
    }

    /**
     * A single file name, nothing a path could be built out of.
     */
    private static function isPlainName(string $filename): bool
    {
        return $filename !== ''
            && strlen($filename) <= self::MAX_NAME_BYTES
            && ! Str::startsWith($filename, '.')
            && ! Str::isMatch('/[\x00-\x1f\x7f\/\\\\]/', $filename);
    }

    /**
     * The upload's recorded state, only for the person who began it.
     *
     * Someone else's upload answers exactly as a missing one does, so an id
     * says nothing about whether it exists.
     *
     * @throws UploadRejected
     */
    private function pending(string $id, int $userId): PendingUpload
    {
        $upload = Str::isUuid($id) ? PendingUpload::fromArray(Cache::get(self::cacheKey($id))) : null;

        if ($upload === null || $upload->userId !== $userId) {
            throw UploadRejected::because(UploadRejection::Unknown);
        }

        return $upload;
    }

    /**
     * The upload's staging file, which has to be a plain file still.
     *
     * @throws UploadRejected
     */
    private function stagedPart(PendingUpload $upload): string
    {
        try {
            $part = $this->partFor($upload->id);
        } catch (LibraryPathException) {
            throw UploadRejected::because(UploadRejection::Unwritable);
        }

        clearstatcache(true, $part);

        if (is_link($part) || ! is_file($part)) {
            Cache::forget(self::cacheKey($upload->id));

            throw UploadRejected::because(UploadRejection::Unknown);
        }

        return $part;
    }

    /**
     * Copy at most $limit bytes of the stream onto the end of the file.
     *
     * @param  resource  $stream
     *
     * @throws UploadRejected
     */
    private function write(string $part, $stream, int $limit): int
    {
        $out = fopen($part, 'ab');

        if ($out === false) {
            throw UploadRejected::because(UploadRejection::Unwritable);
        }

        try {
            $written = stream_copy_to_stream($stream, $out, $limit);
        } finally {
            fclose($out);
        }

        if ($written === false) {
            throw UploadRejected::because(UploadRejection::Unwritable);
        }

        return $written;
    }

    /**
     * Cut a staging file back to a length it had before.
     */
    private function truncate(string $part, int $length): void
    {
        $handle = fopen($part, 'r+');

        if ($handle === false) {
            return;
        }

        ftruncate($handle, max(0, $length));
        fclose($handle);
    }

    /**
     * Forget an upload and delete whatever of it was staged.
     */
    private function discard(string $id): void
    {
        Cache::forget(self::cacheKey($id));

        try {
            File::delete($this->partFor($id));
        } catch (LibraryPathException) {
            // No staging directory means nothing was staged.
        }
    }

    /**
     * Delete staged files nobody has touched in a day.
     *
     * Run from begin() rather than a schedule, because nothing runs the
     * scheduler here and a new upload is the moment the space is wanted.
     *
     * @throws LibraryPathException
     */
    private function prune(): void
    {
        $cutoff = now()->subHours(self::TTL_HOURS)->getTimestamp();

        collect(File::files($this->paths->stagingDirectory()))
            ->filter(function (SplFileInfo $file) use ($cutoff): bool {
                return $file->getExtension() === 'part' && $file->getMTime() < $cutoff;
            })
            ->each(function (SplFileInfo $file): void {
                File::delete($file->getPathname());
            });
    }

    /**
     * Absolute path of an upload's staging file.
     *
     * @throws LibraryPathException
     */
    private function partFor(string $id): string
    {
        return $this->paths->stagingDirectory().'/'.$id.'.part';
    }

    /**
     * Where an upload's state is kept between requests.
     */
    private static function cacheKey(string $id): string
    {
        return 'rom-upload:'.$id;
    }
}
