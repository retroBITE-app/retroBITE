<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MediaKind;
use App\Support\CappedFileSink;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Downloads provider media (cover / logo / backdrop) to a local dir served by
 * nginx and returns `/storage/metadata/...` URLs so the browser never sees the
 * provider URL (which carries dev credentials in its query string).
 */
class MediaCacheService
{
    private const MAX_BYTES = 5 * 1024 * 1024;
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
     * Options every request shares. Redirects are followed by hand so each hop's
     * host is re-checked, and the protocol allowlist blocks file://, dict:// and
     * the rest of curl's repertoire.
     *
     * Merge these with array_replace, never with `[...]`: unpacking renumbers
     * integer keys, so every CURLOPT_* would arrive as 0, 1, 2 and curl would
     * reject the whole array.
     */
    private const CURL_OPTIONS = [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
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
                'kind'   => $kind->value,
                'md5'    => $md5,
                'reason' => $e->getMessage(),
            ]);

            return null;
        } catch (Throwable $e) {
            // Caching artwork is best-effort; the identification it belongs to
            // must still succeed. Logged louder than a skip because reaching
            // here means a bug, not a bad URL.
            logger()->error('Media download failed unexpectedly', [
                'kind'      => $kind->value,
                'md5'       => $md5,
                'exception' => $e::class,
                'reason'    => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Walk the redirect chain by hand, re-validating the host at every hop.
     * curl's own FOLLOWLOCATION checks nothing, so one 302 from an allowed host
     * would otherwise reach the loopback interface or the metadata service.
     *
     * @throws RuntimeException
     */
    private function resolveRedirects(string $url): string
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $this->assertFetchable($url);

            $location = $this->redirectTarget($url);

            if ($location === null) {
                return $url;
            }

            $url = $location;
        }

        throw new RuntimeException('Too many redirects');
    }

    /**
     * The shared options with this request's own merged over them.
     *
     * @param array<int, mixed> $extra
     * @return array<int, mixed>
     */
    private function curlOptions(array $extra): array
    {
        // The artwork comes from the same host as the metadata, so it waits just
        // as long for a connection.
        $connect = [
            CURLOPT_CONNECTTIMEOUT => (int) config('settings.screenscraper.connect_timeout', 15),
        ];

        return array_replace(self::CURL_OPTIONS, $connect, $extra);
    }

    /**
     * Where this URL redirects to, or null when it does not redirect.
     *
     * @throws RuntimeException When it redirects without saying where.
     */
    private function redirectTarget(string $url): ?string
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, $this->curlOptions([
            CURLOPT_NOBODY         => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_RETURNTRANSFER => true,
        ]));

        curl_exec($curl);
        $status   = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $location = (string) curl_getinfo($curl, CURLINFO_REDIRECT_URL);
        curl_close($curl);

        if ($status < 300 || $status >= 400) {
            return null;
        }

        if ($location === '') {
            throw new RuntimeException("Redirect with no location (HTTP {$status})");
        }

        return $location;
    }

    /**
     * Stream the body to a temp file beside the cache, capped at MAX_BYTES.
     *
     * @return array{0: string, 1: string} Temp path and content type.
     * @throws RuntimeException
     */
    private function fetch(string $url): array
    {
        $tmpPath = $this->cacheDir() . '/.' . bin2hex(random_bytes(8)) . '.part';
        $handle  = @fopen($tmpPath, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Could not open a temp file');
        }

        $sink = new CappedFileSink($handle, self::MAX_BYTES);

        [$status, $contentType] = $this->transfer($url, $sink);
        fclose($handle);

        if ($failure = $sink->failure()) {
            @unlink($tmpPath);
            throw new RuntimeException($failure);
        }

        if ($status < 200 || $status >= 300 || $sink->received() === 0) {
            @unlink($tmpPath);
            throw new RuntimeException("Unusable response (HTTP {$status}, {$sink->received()} bytes)");
        }

        return [$tmpPath, $contentType];
    }

    /**
     * Run the transfer, writing through $sink.
     *
     * @return array{0: int, 1: string} Status and content type.
     */
    private function transfer(string $url, CappedFileSink $sink): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, $this->curlOptions([
            CURLOPT_TIMEOUT       => 30,
            CURLOPT_WRITEFUNCTION => fn($handle, string $chunk): int => $sink->write($chunk),
        ]));

        curl_exec($curl);
        $status      = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $contentType = strtolower((string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE));
        curl_close($curl);

        return [$status, $contentType];
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

    /**
     * Is this a lowercase 32-character md5? It becomes a filename.
     */
    private function isValidMd5(string $md5): bool
    {
        return (bool) preg_match('/^[0-9a-f]{32}$/', strtolower($md5));
    }

    /**
     * File extension for an allow-listed content type, or null.
     */
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
