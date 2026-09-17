<?php

namespace App\Resources;

use App\Support\DocPath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

final class DocResource
{
    /** Characters of body text kept for the list rail's preview line. */
    private const EXCERPT_LENGTH = 160;

    /** A markdown image whose target is this document's own media folder. */
    private const ATTACHMENT_PATTERN = '#!\[[^\]]*\]\('.DocPath::MEDIA_DIR.'/([^)\s]+)\)#';

    public readonly string $path;

    public readonly string $title;

    /** Console key, or '' for a document filed at the docs root. */
    public readonly string $console;

    public readonly string $category;

    /** @var string[] */
    public readonly array $tags;

    public readonly string $body;

    public readonly int $bytes;

    public readonly int $revisions;

    public readonly CarbonImmutable $updatedAt;

    public readonly CarbonImmutable $createdAt;

    /** @var array<string, mixed> */
    private readonly array $payload;

    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(array $payload)
    {
        $this->payload = $payload;
        $this->path = (string) Arr::get($payload, 'path', '');
        $this->console = (string) Arr::get($payload, 'console', '');
        $this->category = (string) Arr::get($payload, 'category', '');
        $this->body = (string) Arr::get($payload, 'body', '');
        $this->bytes = (int) Arr::get($payload, 'bytes', 0);
        $this->revisions = (int) Arr::get($payload, 'revisions', 0);
        $this->tags = self::asTags(Arr::get($payload, 'tags'));
        $this->updatedAt = self::asDate(Arr::get($payload, 'updated'));
        $this->createdAt = self::asDate(Arr::get($payload, 'created'), $this->updatedAt);
        $this->title = self::asTitle($payload);
    }

    /**
     * Wrap one payload, or null when there is nothing to wrap.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public static function make(?array $payload): ?self
    {
        return is_array($payload) && Arr::get($payload, 'path') !== null
            ? new self($payload)
            : null;
    }

    /**
     * The payload again, for the index cache to store verbatim.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }

    /**
     * Metadata as it is written back into the file's front matter.
     *
     * @return array<string, mixed>
     */
    public function frontMatter(): array
    {
        return [
            'title' => $this->title,
            'console' => $this->console,
            'category' => $this->category,
            'tags' => $this->tags,
            'created' => $this->createdAt->toIso8601String(),
            'updated' => $this->updatedAt->toIso8601String(),
        ];
    }

    /**
     * Does this document match a free-text query? Title and tags first, because
     * a hit there is what the user meant; body last, because a model number
     * mentioned mid-procedure still has to be findable.
     */
    public function matches(string $query): bool
    {
        $needle = Str::lower(trim($query));

        if ($needle === '') {
            return true;
        }

        return Str::contains(Str::lower($this->title), $needle)
            || Str::contains(Str::lower($this->category), $needle)
            || Str::contains(Str::lower(implode(' ', $this->tags)), $needle)
            || Str::contains(Str::lower($this->body), $needle);
    }

    /**
     * The mono subline under a title in the list rail. Revisions are the more
     * useful fact once a document has any; before that, its first tag is.
     */
    public function subtitle(): string
    {
        $detail = $this->revisions > 0
            ? trans_choice(':count revision|:count revisions', $this->revisions, ['count' => $this->revisions])
            : (string) Arr::first($this->tags);

        return Collection::make([Str::ucfirst($this->category), $detail])
            ->filter(fn (string $part): bool => $part !== '')
            ->implode(' · ');
    }

    /**
     * File size for the meta line.
     *
     * Hand-rolled rather than Number::fileSize(). That needed the intl
     * extension, which the runtime image and CI now both carry — so this is
     * kept only because it works, not because it has to exist.
     */
    public function humanSize(): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $this->bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return ($unit === 0 ? (string) (int) $size : number_format($size, 1)).' '.$units[$unit];
    }

    /**
     * Attachment filenames this document links to.
     *
     * Counted from the markdown rather than from the media folder, because that
     * folder is shared by every document of the same console — "2 attached"
     * has to mean this document's two, not the console's twelve.
     *
     * @return string[]
     */
    public function attachments(): array
    {
        preg_match_all(self::ATTACHMENT_PATTERN, $this->body, $matches);

        return Collection::make($matches[1])->unique()->values()->all();
    }

    /**
     * First line of prose, for an empty-metadata fallback and the search list.
     */
    public function excerpt(): string
    {
        $prose = Collection::make(explode("\n", $this->body))
            ->map(fn (string $line): string => trim($line))
            ->first(fn (string $line): bool => $line !== '' && ! Str::startsWith($line, ['#', '>', '-', '|', '!', '@']));

        return Str::limit((string) $prose, self::EXCERPT_LENGTH);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function asTitle(array $payload): string
    {
        $title = trim((string) Arr::get($payload, 'title', ''));

        if ($title !== '') {
            return $title;
        }

        // No front matter: fall back to the first heading, then to the filename,
        // so an imported or hand-written file is never listed as untitled.
        $heading = Collection::make(explode("\n", (string) Arr::get($payload, 'body', '')))
            ->first(fn (string $line): bool => Str::startsWith(trim($line), '# '));

        return $heading !== null
            ? trim(Str::after(trim($heading), '# '))
            : Str::headline((string) Str::of((string) Arr::get($payload, 'path', ''))->afterLast('/')->beforeLast('.'));
    }

    /**
     * @return string[]
     */
    private static function asTags(mixed $tags): array
    {
        return Collection::make(is_array($tags) ? $tags : [])
            ->map(fn (mixed $tag): string => trim((string) $tag))
            ->filter(fn (string $tag): bool => $tag !== '')
            ->values()
            ->all();
    }

    /**
     * Dates arrive as an ISO string from front matter or an epoch from stat.
     */
    private static function asDate(mixed $value, ?CarbonImmutable $fallback = null): CarbonImmutable
    {
        if (is_int($value)) {
            return CarbonImmutable::createFromTimestamp($value);
        }

        if (is_string($value) && $value !== '') {
            try {
                return CarbonImmutable::parse($value);
            } catch (Throwable) {
                // A hand-edited date that Carbon cannot read is not worth losing
                // the document over — fall through to the file's own mtime.
            }
        }

        return $fallback ?? CarbonImmutable::now();
    }
}
