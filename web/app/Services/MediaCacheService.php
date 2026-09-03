<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MediaKind;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Downloads provider media (cover / logo / backdrop) to a local dir served by
 * nginx and returns `/storage/metadata/...` URLs so the browser never sees the
 * provider URL (which carries dev credentials in its query string).
 */
class MediaCacheService
{
    private const MAX_BYTES     = 5 * 1024 * 1024;
    private const MAX_REDIRECTS = 3;
    private const ALLOWED_HOSTS = ['screenscraper.fr', 'neoclone.screenscraper.fr', 'api.screenscraper.fr'];
    private const ALLOWED_TYPES = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    /**
     * Cache every artwork slot present in $urls, keyed by MediaKind value.
     *
     * @param array<string, ?string> $urls Keyed by MediaKind value.
     * @return array<string, ?string> Local URLs, null where caching failed.
     */
    public function downloadFor(string $md5, array $urls): array
    {
        $cached = [];

        foreach (MediaKind::cases() as $kind) {
            $cached[$kind->value] = $this->download(
                (string) Arr::get($urls, $kind->value, ''),
                $md5,
                $kind,
            );
        }

        return $cached;
    }

    /**
     * Download one image; returns the local URL, or null on any failure (logged).
     */
    private function download(string $sourceUrl, string $md5, MediaKind $kind): ?string
    {
        if ($sourceUrl === '') {
            return null;
        }

        try {
            if (!$this->isValidMd5($md5)) {
                throw new RuntimeException('Invalid md5');
            }

            $url = $this->resolveRedirects($sourceUrl);

            [$tmpPath, $contentType] = $this->fetch($url);

            return $this->commit($tmpPath, $contentType, $md5, $kind);
        } catch (RuntimeException $e) {
            logger()->debug('Media download skipped', [
                'kind'    => $kind->value,
                'md5'     => $md5,
                'reason'  => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Walk the redirect chain by hand, re-validating the host at every hop.
     * curl's own FOLLOWLOCATION checks nothing, so one 302 from an allowed host
     * would otherwise reach the loopback interface or the metadata service.
     */
    private function resolveRedirects(string $url): string
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $this->assertFetchable($url);

            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_NOBODY         => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_RETURNTRANSFER => true,
            ]);

            curl_exec($handle);
            $status   = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $location = (string) curl_getinfo($handle, CURLINFO_REDIRECT_URL);
            curl_close($handle);

            if ($status < 300 || $status >= 400) {
                return $url;
            }

            if ($location === '') {
                throw new RuntimeException("Redirect with no location (HTTP {$status})");
            }

            $url = $location;
        }

        throw new RuntimeException('Too many redirects');
    }

    /**
     * Stream the body to a temp file beside the cache, capped at MAX_BYTES.
     *
     * @return array{0: string, 1: string} Temp path and content type.
     */
    private function fetch(string $url): array
    {
        $tmpPath = $this->cacheDir() . '/.' . bin2hex(random_bytes(8)) . '.part';
        $handle  = @fopen($tmpPath, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Could not open a temp file');
        }

        $received = 0;
        $failure  = null;

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_WRITEFUNCTION  => function ($curl, string $chunk) use (&$received, &$failure, $handle): int {
                $received += strlen($chunk);

                if ($received > self::MAX_BYTES) {
                    $failure = 'Image exceeds the size limit';

                    return -1;
                }

                $written = fwrite($handle, $chunk);

                // A short or failed write must abort: coercing it to 0 previously
                // published a truncated image as a successful cache entry.
                if ($written !== strlen($chunk)) {
                    $failure = 'Could not write the downloaded image';

                    return -1;
                }

                return $written;
            },
        ]);

        curl_exec($curl);
        $status      = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $contentType = strtolower((string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE));
        curl_close($curl);
        fclose($handle);

        if ($failure !== null) {
            @unlink($tmpPath);
            throw new RuntimeException($failure);
        }

        if ($status < 200 || $status >= 300 || $received === 0) {
            @unlink($tmpPath);
            throw new RuntimeException("Unusable response (HTTP {$status}, {$received} bytes)");
        }

        return [$tmpPath, $contentType];
    }

    /**
     * Publish the temp file under its final name and drop stale siblings.
     */
    private function commit(string $tmpPath, string $contentType, string $md5, MediaKind $kind): string
    {
        $ext = $this->extensionFor($contentType);

        if ($ext === null) {
            @unlink($tmpPath);
            throw new RuntimeException("Unsupported content type '{$contentType}'");
        }

        $filename = "{$md5}_{$kind->value}.{$ext}";
        $destPath = $this->cacheDir() . '/' . $filename;

        // Same directory as the temp file, so this is a rename and not a copy.
        if (!@rename($tmpPath, $destPath)) {
            @unlink($tmpPath);
            throw new RuntimeException('Could not move the image into the cache');
        }

        @chmod($destPath, 0644);
        $this->removeStaleSiblings($md5, $kind, $destPath);

        return rtrim((string) config('settings.storage.metadata_url'), '/') . '/' . $filename;
    }

    /**
     * Delete the same image cached under a different extension (was .png, now .jpg).
     */
    private function removeStaleSiblings(string $md5, MediaKind $kind, string $keepPath): void
    {
        foreach (self::ALLOWED_TYPES as $ext) {
            $other = $this->cacheDir() . "/{$md5}_{$kind->value}.{$ext}";

            if ($other !== $keepPath && is_file($other)) {
                @unlink($other);
            }
        }
    }

    /**
     * Assert a URL is one we are willing to request: an allow-listed host over
     * plain HTTP(S) that resolves to a public address.
     */
    private function assertFetchable(string $url): void
    {
        $parts  = parse_url($url) ?: [];
        $scheme = strtolower((string) Arr::get($parts, 'scheme', ''));
        $host   = strtolower((string) Arr::get($parts, 'host', ''));

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException("Refusing scheme '{$scheme}'");
        }

        if (!in_array($host, self::ALLOWED_HOSTS, true)) {
            throw new RuntimeException("Host '{$host}' is not allow-listed");
        }

        $this->assertPublicHost($host);
    }

    /**
     * Reject a host that resolves into private, loopback or link-local space —
     * defence in depth against a poisoned or repointed DNS record.
     */
    private function assertPublicHost(string $host): void
    {
        $ip = gethostbyname($host);

        if ($ip === $host) {
            throw new RuntimeException("Could not resolve '{$host}'");
        }

        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );

        if ($isPublic === false) {
            throw new RuntimeException("Host '{$host}' resolves to a non-public address");
        }
    }

    private function isValidMd5(string $md5): bool
    {
        return (bool) preg_match('/^[0-9a-f]{32}$/', strtolower($md5));
    }

    private function extensionFor(string $contentType): ?string
    {
        return Arr::get(self::ALLOWED_TYPES, trim(Str::before($contentType, ';')));
    }

    /**
     * The local cache directory, created on first use.
     */
    private function cacheDir(): string
    {
        $dir = (string) config('settings.storage.metadata_path');

        if ($dir === '') {
            throw new RuntimeException('Media cache path is not configured');
        }

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create the media cache directory');
        }

        return $dir;
    }
}
