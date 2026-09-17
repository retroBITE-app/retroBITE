<?php

namespace App\Support\Markdown;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use League\CommonMark\Node\Inline\AbstractInline;

final class VideoEmbed extends AbstractInline
{
    /** Eleven characters of the YouTube alphabet, and nothing else. */
    private const ID_PATTERN = '/^[A-Za-z0-9_-]{11}$/';

    /** The only hosts an embed is accepted from. */
    private const HOSTS = ['youtu.be', 'youtube.com', 'www.youtube.com', 'm.youtube.com'];

    public function __construct(public readonly string $id)
    {
        parent::__construct();
    }

    /**
     * The video id in a YouTube URL, or null for anything we will not embed.
     *
     * The URL is never fetched — only parsed — so a doc cannot make the server
     * issue a request by naming one.
     */
    public static function idFrom(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        $host = Str::lower((string) Arr::get($parts, 'host', ''));

        if (! in_array($host, self::HOSTS, true)) {
            return null;
        }

        parse_str((string) Arr::get($parts, 'query', ''), $query);

        $path = trim((string) Arr::get($parts, 'path', ''), '/');
        $v = Arr::get($query, 'v');

        $candidate = $host === 'youtu.be'
            ? $path
            : (is_string($v) ? $v : (string) Str::afterLast($path, '/'));

        return preg_match(self::ID_PATTERN, $candidate) === 1 ? $candidate : null;
    }
}
