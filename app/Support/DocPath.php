<?php

namespace App\Support;

use App\Exceptions\DocPathException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final class DocPath
{
    public const MEDIA_DIR = 'media';

    public const REVISIONS_DIR = '.revisions';

    public const DOC_EXTENSION = 'md';

    public const MEDIA_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    public const FALLBACK_SLUG = 'untitled';

    private const SEGMENT = '[A-Za-z0-9_-]+';

    public function __construct(private readonly string $root) {}

    /**
     * The docs root this gate is anchored to.
     */
    public function root(): string
    {
        return $this->root;
    }

    /**
     * Relative path of the document a console key and slug name. No console
     * files it at the root, beside the console folders.
     *
     * @throws DocPathException
     */
    public function document(string $console, string $slug): string
    {
        $filename = $slug.'.'.self::DOC_EXTENSION;

        return $this->assertDocument($console === '' ? $filename : $console.'/'.$filename);
    }

    /**
     * Admit a document path that arrived from the client, returning it
     * unchanged for use against the `docs` disk.
     *
     * @throws DocPathException
     */
    public function assertDocument(string $relative): string
    {
        if (! self::looksLikeDocument($relative)) {
            throw DocPathException::malformed($relative);
        }

        return $this->assertWithin($relative);
    }

    /**
     * Admit an attachment path, which must sit in a `media` folder.
     *
     * @throws DocPathException
     */
    public function assertMedia(string $relative): string
    {
        if (! self::looksLikeMedia($relative)) {
            throw DocPathException::malformed($relative);
        }

        return $this->assertWithin($relative);
    }

    /**
     * The media folder beside a document, e.g. "ps2/media".
     *
     * @throws DocPathException
     */
    public function mediaDirectory(string $document): string
    {
        $parent = Str::beforeLast($this->assertDocument($document), '/');

        return $parent === $document ? self::MEDIA_DIR : $parent.'/'.self::MEDIA_DIR;
    }

    /**
     * Relative path of one attachment of a document.
     *
     * @throws DocPathException
     */
    public function mediaFor(string $document, string $filename): string
    {
        return $this->assertMedia($this->mediaDirectory($document).'/'.$filename);
    }

    /**
     * How an attachment is linked from inside the markdown: relative to the
     * document, so the link survives being opened outside retroBITE.
     */
    public static function mediaLink(string $filename): string
    {
        return self::MEDIA_DIR.'/'.$filename;
    }

    /**
     * The revision folder for a document, e.g. ".revisions/ps2/scph-39001-laser".
     *
     * The literal folder name carries a dot the grammar would refuse, so only
     * the document part of the path is checked against it — the prefix is ours.
     *
     * @throws DocPathException
     */
    public function revisionDirectory(string $document): string
    {
        $stem = Str::beforeLast($this->assertDocument($document), '.');

        return $this->assertWithin(self::REVISIONS_DIR.'/'.$stem);
    }

    /**
     * One revision of a document, named for the moment it was superseded.
     *
     * @throws DocPathException
     */
    public function revision(string $document, int $timestamp): string
    {
        return $this->revisionDirectory($document).'/'.$timestamp.'.'.self::DOC_EXTENSION;
    }

    /**
     * Absolute path on disk, for the few callers that cannot use the disk —
     * ZipArchive and file responses.
     *
     * @throws DocPathException
     */
    public function absolute(string $relative): string
    {
        return $this->root.'/'.$this->assertWithin($relative);
    }

    /**
     * Filename-safe stem for a title, falling back when it slugs to nothing.
     */
    public static function slug(string $title): string
    {
        $slug = Str::slug($title);

        return $slug === '' ? self::FALLBACK_SLUG : $slug;
    }

    /**
     * Grammar check only, for filtering a directory listing that came off disk
     * rather than out of a request.
     */
    public static function looksLikeDocument(string $relative): bool
    {
        if (! self::isWellFormed($relative, [self::DOC_EXTENSION])) {
            return false;
        }

        // Keep the two trees disjoint: a .md sitting in an attachments folder is
        // not a document, whatever it contains.
        return ! in_array(self::MEDIA_DIR, self::parents($relative), true);
    }

    /**
     * Grammar check only. An attachment must live directly in a `media` folder.
     */
    public static function looksLikeMedia(string $relative): bool
    {
        return self::isWellFormed($relative, self::MEDIA_EXTENSIONS)
            && Arr::last(self::parents($relative)) === self::MEDIA_DIR;
    }

    /**
     * Does the shape hold up before anything touches the disk?
     *
     * @param  string[]  $extensions
     */
    private static function isWellFormed(string $relative, array $extensions): bool
    {
        if ($relative === '' || Str::contains($relative, ["\0", '\\'])) {
            return false;
        }

        // A leading slash is an absolute path; a trailing one names a folder.
        if (Str::startsWith($relative, '/') || Str::endsWith($relative, '/')) {
            return false;
        }

        foreach (self::parents($relative) as $segment) {
            if (! self::isSegment($segment)) {
                return false;
            }
        }

        return self::isFilename((string) Str::afterLast($relative, '/'), $extensions);
    }

    /**
     * The folder segments of a relative path, the filename dropped.
     *
     * @return string[]
     */
    private static function parents(string $relative): array
    {
        $segments = explode('/', $relative);

        array_pop($segments);

        return $segments;
    }

    /**
     * A name plus one extension we recognise, and nothing dotted in between.
     *
     * @param  string[]  $extensions
     */
    private static function isFilename(string $filename, array $extensions): bool
    {
        if (! Str::contains($filename, '.')) {
            return false;
        }

        $extension = Str::lower((string) Str::afterLast($filename, '.'));

        return in_array($extension, $extensions, true)
            && self::isSegment((string) Str::beforeLast($filename, '.'));
    }

    /**
     * One dot-free path segment.
     */
    private static function isSegment(string $segment): bool
    {
        return preg_match('/^'.self::SEGMENT.'$/', $segment) === 1;
    }

    /**
     * The containment gate. Resolves the path — existing or not — and refuses
     * it unless it lands strictly inside the docs root.
     *
     * @throws DocPathException
     */
    private function assertWithin(string $relative): string
    {
        $base = self::resolveIntended($this->root);
        $target = self::resolveIntended($this->root.'/'.$relative);

        if ($base === null || $target === null) {
            throw DocPathException::unresolvable($relative);
        }

        if ($target === $base || ! Str::startsWith($target.'/', $base.'/')) {
            throw DocPathException::outsideRoot($relative);
        }

        $this->assertNoSymlink($relative);

        return $relative;
    }

    /**
     * Refuse a path that crosses a symlink. realpath() below already catches a
     * link pointing out of the root, but the docs directory can be bind-mounted
     * somewhere the share container writes, so a link is refused on sight.
     *
     * @throws DocPathException
     */
    private function assertNoSymlink(string $relative): void
    {
        $current = $this->root;

        foreach (explode('/', $relative) as $segment) {
            $current .= '/'.$segment;

            if (is_link($current)) {
                throw DocPathException::symlink($relative);
            }

            // Nothing beyond this point exists yet, so there is nothing to follow.
            if (! file_exists($current)) {
                return;
            }
        }
    }

    /**
     * Resolve a path that may not exist yet, through its nearest existing
     * ancestor — so a new document can be checked for containment before the
     * folder it goes in has been created.
     */
    private static function resolveIntended(string $path): ?string
    {
        $existing = realpath($path);

        if ($existing !== false) {
            return $existing;
        }

        $missing = [];
        $current = rtrim($path, '/');

        while ($current !== '' && $current !== '/' && ! file_exists($current)) {
            $missing[] = basename($current);
            $current = dirname($current);
        }

        $anchor = realpath($current);

        if ($anchor === false) {
            return null;
        }

        return $missing === []
            ? $anchor
            : $anchor.'/'.implode('/', array_reverse($missing));
    }
}
