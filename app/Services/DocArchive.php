<?php

namespace App\Services;

use App\Exceptions\DocPathException;
use App\Resources\DocResource;
use App\Support\DocPath;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class DocArchive
{
    private const DISK = 'docs';

    /** Entries an imported archive may hold before we stop trusting it. */
    private const MAX_ENTRIES = 64;

    /** Total uncompressed bytes allowed, so a zip bomb cannot be unpacked. */
    private const MAX_BYTES = 64 * 1024 * 1024;

    /** Archive members produced by desktop zip tools that carry nothing of ours. */
    private const IGNORED = ['__MACOSX/', '.DS_Store', 'Thumbs.db'];

    public function __construct(private readonly DocPath $paths) {}

    /**
     * Can this build an archive at all?
     *
     * The php-fpm image installs ext-zip, but a host PHP running artisan may
     * not have it, so the action is hidden rather than offered and then failing.
     */
    public function available(): bool
    {
        return extension_loaded('zip');
    }

    /**
     * Write a zip to a temporary file and return its absolute path. The caller
     * is responsible for deleting it, which the download response does.
     */
    public function write(DocResource $doc): string
    {
        if (! $this->available()) {
            throw new RuntimeException('ext-zip is not loaded');
        }

        $archive = (string) tempnam(sys_get_temp_dir(), 'retrobite-doc-');
        $zip = new ZipArchive;

        if ($zip->open($archive, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open an archive for '.$doc->path);
        }

        $disk = Storage::disk(self::DISK);
        $name = (string) Str::afterLast($doc->path, '/');

        $zip->addFromString($name, (string) $disk->get($this->paths->assertDocument($doc->path)));

        foreach ($doc->attachments() as $filename) {
            try {
                $relative = $this->paths->mediaFor($doc->path, $filename);
            } catch (DocPathException) {
                // A link we would never serve is not one we will bundle either.
                continue;
            }

            if ($disk->exists($relative)) {
                $zip->addFromString(DocPath::mediaLink($filename), (string) $disk->get($relative));
            }
        }

        $zip->close();

        return $archive;
    }

    /**
     * Read a bundle of the shape write() produces: one markdown file at the
     * root, and the media folder beside it.
     *
     * @return array{name: string, markdown: string, media: array<string, string>}
     *
     * @throws RuntimeException
     */
    public function read(string $archive): array
    {
        if (! $this->available()) {
            throw new RuntimeException('ext-zip is not loaded');
        }

        $zip = new ZipArchive;

        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Could not open the archive');
        }

        try {
            return $this->members($zip);
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array{name: string, markdown: string, media: array<string, string>}
     *
     * @throws RuntimeException
     */
    private function members(ZipArchive $zip): array
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw new RuntimeException('Archive holds too many entries');
        }

        $name = null;
        $markdown = '';
        $media = [];
        $bytes = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);

            if ($stat === false || self::skippable((string) Arr::get($stat, 'name'))) {
                continue;
            }

            $bytes += (int) Arr::get($stat, 'size', 0);

            if ($bytes > self::MAX_BYTES) {
                throw new RuntimeException('Archive unpacks to more than we accept');
            }

            $entry = (string) Arr::get($stat, 'name');

            if (self::isRootDocument($entry)) {
                if ($name !== null) {
                    throw new RuntimeException('Archive holds more than one document');
                }

                $name = $entry;
                $markdown = (string) $zip->getFromIndex($index);

                continue;
            }

            if (self::isAttachment($entry)) {
                $media[(string) Str::afterLast($entry, '/')] = (string) $zip->getFromIndex($index);
            }
        }

        if ($name === null) {
            throw new RuntimeException('Archive holds no document');
        }

        return ['name' => $name, 'markdown' => $markdown, 'media' => $media];
    }

    /**
     * A directory entry, or noise a desktop zip tool added.
     */
    private static function skippable(string $entry): bool
    {
        return Str::endsWith($entry, '/')
            || Str::startsWith($entry, self::IGNORED)
            || Str::startsWith((string) Str::afterLast($entry, '/'), '.');
    }

    /**
     * The document itself: a markdown file at the root of the archive.
     */
    private static function isRootDocument(string $entry): bool
    {
        return ! Str::contains($entry, '/')
            && DocPath::looksLikeDocument($entry);
    }

    /**
     * An attachment: directly inside the one media folder, nothing nested.
     */
    private static function isAttachment(string $entry): bool
    {
        return substr_count($entry, '/') === 1 && DocPath::looksLikeMedia($entry);
    }

    /**
     * The archive's filename, taken from the document's own.
     */
    public function filename(DocResource $doc): string
    {
        return Str::of($doc->path)->afterLast('/')->beforeLast('.')->value().'.zip';
    }
}
