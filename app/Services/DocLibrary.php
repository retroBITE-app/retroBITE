<?php

namespace App\Services;

use App\Exceptions\DocPathException;
use App\Resources\ConsoleResource;
use App\Resources\DocResource;
use App\Support\DocFrontMatter;
use App\Support\DocPath;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class DocLibrary
{
    /** Disk defined in config/filesystems.php. Never a hardcoded path. */
    private const DISK = 'docs';

    /** One entry, holding both the fingerprint and the payloads it describes. */
    private const CACHE_KEY = 'docs.index';

    /** Seconds. Long, because the fingerprint — not the clock — decides freshness. */
    private const CACHE_TTL = 86400;

    /**
     * Revisions kept per document before the oldest is dropped.
     */
    private const MAX_REVISIONS = 20;

    /** Filter tokens the chip row emits, so a console key and a category cannot collide. */
    private const FILTER_CONSOLE = 'console';

    private const FILTER_CATEGORY = 'category';

    /** @var Collection<int, DocResource>|null */
    private ?Collection $memo = null;

    public function __construct(private readonly DocPath $paths) {}

    /**
     * Every document, newest first.
     *
     * @return Collection<int, DocResource>
     */
    public function index(): Collection
    {
        if ($this->memo instanceof Collection) {
            return $this->memo;
        }

        // Not Cache::flexible(): the index is checked against a fingerprint of
        // the files on every read and rebuilt only when one has changed, so it
        // is never stale. Serving it stale-while-revalidating would show a
        // document that was just edited or deleted outside the app.
        $files = $this->listing();
        $fingerprint = $this->fingerprint($files);
        $cached = Cache::get(self::CACHE_KEY);

        if (! is_array($cached) || Arr::get($cached, 'fingerprint') !== $fingerprint) {
            $cached = ['fingerprint' => $fingerprint, 'docs' => $this->build($files)];
            Cache::put(self::CACHE_KEY, $cached, self::CACHE_TTL);
        }

        return $this->memo = Collection::make((array) Arr::get($cached, 'docs', []))
            ->map(fn (mixed $payload): ?DocResource => DocResource::make(is_array($payload) ? $payload : null))
            ->filter()
            ->sortByDesc(fn (DocResource $doc): int => $doc->updatedAt->getTimestamp())
            ->values();
    }

    /**
     * One document, or null when the path names nothing we would serve.
     */
    public function find(?string $path): ?DocResource
    {
        if ($path === null || $path === '') {
            return null;
        }

        return $this->index()->first(fn (DocResource $doc): bool => $doc->path === $path);
    }

    /**
     * Documents matching a free-text query and one chip, newest first.
     *
     * @return Collection<int, DocResource>
     */
    public function search(string $query = '', string $filter = ''): Collection
    {
        [$kind, $value] = self::splitFilter($filter);

        return $this->index()
            ->filter(fn (DocResource $doc): bool => match ($kind) {
                self::FILTER_CONSOLE => $doc->console === $value,
                self::FILTER_CATEGORY => $doc->category === $value,
                default => true,
            })
            ->filter(fn (DocResource $doc): bool => $doc->matches($query))
            ->values();
    }

    /**
     * The chip token that filters to one console.
     */
    public static function consoleFilter(string $key): string
    {
        return self::FILTER_CONSOLE.':'.$key;
    }

    /**
     * The chip token that filters to one category.
     */
    public static function categoryFilter(string $name): string
    {
        return self::FILTER_CATEGORY.':'.$name;
    }

    /**
     * Console keys that actually have documents, in config order.
     *
     * Derived from the tree rather than from config/consoles/, so the chip row
     * offers only filters that would return something.
     *
     * @return Collection<int, string>
     */
    public function consoles(): Collection
    {
        $present = $this->index()->pluck('console')->filter()->unique();

        return Collection::make(ConsoleResource::keys())
            ->filter(fn (string $key): bool => $present->contains($key))
            ->values();
    }

    /**
     * Categories in use, alphabetically. Not an enum: the New-doc dialog can
     * invent one, so the vocabulary is whatever the documents say it is.
     *
     * @return Collection<int, string>
     */
    public function categories(): Collection
    {
        return $this->index()
            ->pluck('category')
            ->filter()
            ->unique()
            ->sort()
            ->values();
    }

    /**
     * Write a new document and return it.
     *
     * The path is fixed here and never follows a later title change: renaming
     * the file on every edit would break every link and every bookmark to it.
     *
     * @param  string[]  $tags
     * @param  array<string, string>  $media  filename => contents
     */
    public function create(string $console, string $title, string $category, array $tags, string $body, array $media = []): DocResource
    {
        $path = $this->vacantPath($console, DocPath::slug($title));
        $body = $this->attach($path, $media, $body);
        $now = CarbonImmutable::now();

        $this->disk()->put($path, DocFrontMatter::dump([
            'title' => $title,
            'console' => $console,
            'category' => $category,
            'tags' => array_values($tags),
            'created' => $now->toIso8601String(),
            'updated' => $now->toIso8601String(),
        ], $body));

        return $this->forget()->reread($path);
    }

    /**
     * Overwrite a document's body, and any metadata the caller names.
     *
     * @param  array<string, mixed>  $meta  any of title, console, category, tags
     */
    public function save(string $path, string $body, array $meta = []): DocResource
    {
        $path = $this->paths->assertDocument($path);
        $existing = $this->reread($path);

        $frontMatter = Collection::make(Arr::except($meta, ['created', 'updated']))
            ->reject(fn (mixed $value): bool => $value === null)
            ->all() + $existing->frontMatter();

        Arr::set($frontMatter, 'updated', CarbonImmutable::now()->toIso8601String());

        $next = DocFrontMatter::dump($frontMatter, $body);

        $this->snapshot($path, $next);
        $this->disk()->put($path, $next);

        return $this->forget()->reread($path);
    }

    /**
     * Delete a document, its history, and the attachments it alone was using.
     */
    public function delete(string $path): void
    {
        $path = $this->paths->assertDocument($path);
        $doc = $this->find($path);

        $this->disk()->delete($path);
        $this->disk()->deleteDirectory($this->paths->revisionDirectory($path));

        // Before the orphan check, so the index no longer counts this document
        // among the things still referencing its own attachments.
        $this->forget();

        if ($doc instanceof DocResource) {
            $this->detach($doc);
            $this->forget();
        }
    }

    /**
     * Remove the attachments a deleted document leaves behind unreferenced.
     */
    private function detach(DocResource $doc): void
    {
        $stillUsed = $this->index()
            ->filter(fn (DocResource $other): bool => $other->console === $doc->console)
            ->flatMap(fn (DocResource $other): array => $other->attachments())
            ->unique();

        foreach (Collection::make($doc->attachments())->diff($stillUsed) as $filename) {
            try {
                $this->disk()->delete($this->paths->mediaFor($doc->path, $filename));
            } catch (DocPathException) {
                // A link we would never have served is not one we can delete.
                continue;
            }
        }
    }

    /**
     * Revision timestamps for a document, newest first.
     *
     * Milliseconds, not seconds: two saves a moment apart are ordinary, and at
     * one-second resolution the second one overwrote the first one's snapshot.
     *
     * @return Collection<int, positive-int>
     */
    public function revisions(string $path): Collection
    {
        return Collection::make($this->disk()->files($this->paths->revisionDirectory($path)))
            ->map(fn (string $file): int => (int) Str::of($file)->afterLast('/')->beforeLast('.')->value())
            ->filter(fn (int $timestamp): bool => $timestamp > 0)
            ->sortDesc()
            ->values();
    }

    /**
     * Put an earlier version back. The current one is snapshotted first, by the
     * ordinary save path, so a restore is itself undoable.
     */
    public function restore(string $path, int $timestamp): DocResource
    {
        $revision = $this->paths->revision($path, $timestamp);

        if (! $this->disk()->exists($revision)) {
            throw new RuntimeException('No such revision: '.$revision);
        }

        $parsed = DocFrontMatter::parse((string) $this->disk()->get($revision));

        return $this->save($path, Arr::get($parsed, 'body'), Arr::only(Arr::get($parsed, 'data'), ['title', 'category', 'tags']));
    }

    /**
     * Read an uploaded markdown file without writing anything, so the New-doc
     * dialog can prefill itself from whatever front matter it carries.
     *
     * @return array{title: string, console: string, category: string, tags: string[], body: string}
     */
    public function inspect(string $filename, string $contents): array
    {
        $parsed = DocFrontMatter::parse($contents);
        $doc = DocResource::make([
            'path' => Str::of($filename)->afterLast('/')->value(),
            'body' => Arr::get($parsed, 'body'),
        ] + Arr::get($parsed, 'data'));

        return [
            'title' => $doc->title,
            'console' => $doc->console,
            'category' => $doc->category,
            'tags' => $doc->tags,
            'body' => Arr::get($parsed, 'body'),
        ];
    }

    /**
     * The raw file, for download.
     */
    public function contents(string $path): string
    {
        return (string) $this->disk()->get($this->paths->assertDocument($path));
    }

    /**
     * Read one document straight from disk, bypassing the index.
     */
    private function reread(string $path): DocResource
    {
        return DocResource::make($this->payload($path, $this->revisions($path)->count()))
            ?? throw new RuntimeException('Document disappeared between write and read');
    }

    /**
     * Copy the current version aside before it is overwritten, then prune.
     */
    private function snapshot(string $path, string $next): void
    {
        $disk = $this->disk();

        if (! $disk->exists($path) || $disk->get($path) === $next) {
            return;
        }

        $disk->put($this->paths->revision($path, CarbonImmutable::now()->getTimestampMs()), (string) $disk->get($path));

        $this->revisions($path)
            ->slice(self::MAX_REVISIONS)
            ->each(fn (int $timestamp) => $disk->delete($this->paths->revision($path, $timestamp)));
    }

    /**
     * Write attachments beside a document, renaming on a clash, and return the
     * body with its links pointed at whatever they were actually stored as.
     *
     * @param  array<string, string>  $media  filename => contents
     */
    private function attach(string $document, array $media, string $body): string
    {
        foreach ($media as $filename => $contents) {
            $stored = $this->vacantMedia($document, (string) $filename);

            $this->disk()->put($this->paths->mediaFor($document, $stored), $contents);

            if ($stored !== $filename) {
                $body = str_replace(
                    DocPath::mediaLink((string) $filename),
                    DocPath::mediaLink($stored),
                    $body,
                );
            }
        }

        return $body;
    }

    /**
     * An attachment filename in this console's media folder that is not taken.
     *
     * The name is slugged first, both so it survives the path grammar and so a
     * file named from a phone or a zip cannot dictate its own path.
     */
    private function vacantMedia(string $document, string $filename): string
    {
        $extension = Str::lower((string) Str::afterLast($filename, '.'));
        $stem = DocPath::slug((string) Str::beforeLast($filename, '.'));

        $candidate = $stem.'.'.$extension;

        for ($suffix = 2; $this->disk()->exists($this->paths->mediaFor($document, $candidate)); $suffix++) {
            $candidate = $stem.'-'.$suffix.'.'.$extension;
        }

        return $candidate;
    }

    /**
     * A document path in this console that is not taken, suffixing on a clash
     * so two documents with the same title cannot overwrite each other.
     */
    private function vacantPath(string $console, string $slug): string
    {
        $candidate = $this->paths->document($console, $slug);

        for ($suffix = 2; $this->disk()->exists($candidate); $suffix++) {
            $candidate = $this->paths->document($console, $slug.'-'.$suffix);
        }

        return $candidate;
    }

    /**
     * Every file on the disk, in one listing — the revision and attachment
     * counts are derived from it rather than costing a directory read each.
     *
     * @return string[]
     */
    private function listing(): array
    {
        return $this->disk()->allFiles();
    }

    /**
     * A hash that changes whenever anything the index depends on does.
     *
     * Documents contribute their mtime and size; everything else contributes
     * only its path, which is enough to notice a revision or an attachment
     * appearing without stat-ing the whole tree.
     *
     * @param  string[]  $files
     */
    private function fingerprint(array $files): string
    {
        $disk = $this->disk();

        return md5(Collection::make($files)
            ->sort()
            ->map(fn (string $file): string => DocPath::looksLikeDocument($file)
                ? $file.':'.$disk->lastModified($file).':'.$disk->size($file)
                : $file)
            ->implode('|'));
    }

    /**
     * Parse every document in a listing into the flat payloads the cache holds.
     *
     * @param  string[]  $files
     * @return array<int, array<string, mixed>>
     */
    private function build(array $files): array
    {
        $revisions = Collection::make($files)
            ->filter(fn (string $file): bool => Str::startsWith($file, DocPath::REVISIONS_DIR.'/'))
            ->countBy(fn (string $file): string => (string) Str::beforeLast($file, '/'));

        return Collection::make($files)
            ->filter(fn (string $file): bool => DocPath::looksLikeDocument($file))
            ->map(fn (string $file): array => $this->payload(
                $file,
                (int) $revisions->get($this->paths->revisionDirectory($file), 0),
            ))
            ->values()
            ->all();
    }

    /**
     * One document as the flat array DocResource wraps and the cache stores.
     *
     * @return array<string, mixed>
     */
    private function payload(string $path, int $revisions): array
    {
        $disk = $this->disk();
        $parsed = DocFrontMatter::parse((string) $disk->get($path));

        return [
            'path' => $path,
            'body' => Arr::get($parsed, 'body'),
            'bytes' => $disk->size($path),
            'updated' => Arr::get($parsed, 'data.updated', $disk->lastModified($path)),
            'created' => Arr::get($parsed, 'data.created'),
            'revisions' => $revisions,
            'console' => Arr::get($parsed, 'data.console', Str::before($path, '/') === $path ? '' : Str::before($path, '/')),
            'category' => Arr::get($parsed, 'data.category', ''),
            'title' => Arr::get($parsed, 'data.title', ''),
            'tags' => Arr::get($parsed, 'data.tags', []),
        ];
    }

    /**
     * Drop the index so the next read rebuilds it. The fingerprint would notice
     * anyway; this keeps a write and a read in the same request consistent.
     */
    private function forget(): self
    {
        Cache::forget(self::CACHE_KEY);
        $this->memo = null;

        return $this;
    }

    /**
     * Split a chip token like "console:ps2" into its parts.
     *
     * @return array{0: string, 1: string}
     */
    private static function splitFilter(string $filter): array
    {
        return Str::contains($filter, ':')
            ? [(string) Str::before($filter, ':'), (string) Str::after($filter, ':')]
            : ['', ''];
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
