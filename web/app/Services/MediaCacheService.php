<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Downloads provider media (cover / logo / backdrop) to a local dir served by
 * nginx and returns `/storage/metadata/...` URLs so the browser never sees the
 * provider URL (which carries dev credentials in its query string).
 */
class MediaCacheService
{
    private const MAX_BYTES      = 5 * 1024 * 1024;
    private const ALLOWED_HOSTS  = ['screenscraper.fr', 'neoclone.screenscraper.fr', 'api.screenscraper.fr'];
    private const ALLOWED_TYPES  = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    /**
     * @param array{cover?: ?string, logo?: ?string, backdrop?: ?string} $urls
     * @return array{cover: ?string, logo: ?string, backdrop: ?string}
     */
    public function downloadFor(string $md5, array $urls): array
    {
        return [
            'cover'    => $this->download((string) Arr::get($urls, 'cover',    ''), $md5, 'cover'),
            'logo'     => $this->download((string) Arr::get($urls, 'logo',     ''), $md5, 'logo'),
            'backdrop' => $this->download((string) Arr::get($urls, 'backdrop', ''), $md5, 'backdrop'),
        ];
    }

    /**
     * Download one image; returns the local URL or null on any failure.
     */
    private function download(string $sourceUrl, string $md5, string $kind): ?string
    {
        if ($sourceUrl === '' || !$this->isAllowedHost($sourceUrl) || !$this->isValidMd5($md5)) {
            return null;
        }

        $this->ensureDir();

        $tmpPath = tempnam(sys_get_temp_dir(), 'rb-media-') ?: null;
        if ($tmpPath === null) {
            return null;
        }

        $fp = fopen($tmpPath, 'wb');
        if ($fp === false) {
            @unlink($tmpPath);
            return null;
        }

        $received = 0;
        $aborted  = false;

        $ch = curl_init($sourceUrl);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FILE           => $fp,
            CURLOPT_WRITEFUNCTION  => function ($_ch, string $chunk) use (&$received, &$aborted, $fp) {
                $received += strlen($chunk);
                if ($received > self::MAX_BYTES) {
                    $aborted = true;
                    return -1; // abort transfer
                }
                return fwrite($fp, $chunk) ?: 0;
            },
        ]);

        curl_exec($ch);
        $status      = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = strtolower((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
        curl_close($ch);
        fclose($fp);

        if ($aborted || $status < 200 || $status >= 300 || $received === 0) {
            @unlink($tmpPath);
            return null;
        }

        $ext = $this->extensionFor($contentType);
        if ($ext === null) {
            @unlink($tmpPath);
            return null;
        }

        $filename = "{$md5}_{$kind}.{$ext}";
        $destPath = config('settings.storage.metadata_path') . '/' . $filename;

        if (!@rename($tmpPath, $destPath)) {
            @unlink($tmpPath);
            return null;
        }
        @chmod($destPath, 0644);

        // Clean up any stale sibling with a different extension (e.g. was .png, now .jpg)
        foreach (self::ALLOWED_TYPES as $otherExt) {
            $other = config('settings.storage.metadata_path') . "/{$md5}_{$kind}.{$otherExt}";
            if ($other !== $destPath && is_file($other)) {
                @unlink($other);
            }
        }

        return rtrim((string) config('settings.storage.metadata_url'), '/') . '/' . $filename;
    }

    private function isAllowedHost(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host !== '' && in_array($host, self::ALLOWED_HOSTS, true);
    }

    private function isValidMd5(string $md5): bool
    {
        return (bool) preg_match('/^[0-9a-f]{32}$/', strtolower($md5));
    }

    private function extensionFor(string $contentType): ?string
    {
        $type = Str::before($contentType, ';');

        return Arr::get(self::ALLOWED_TYPES, trim($type));
    }

    private function ensureDir(): void
    {
        $dir = (string) config('settings.storage.metadata_path');

        if ($dir !== '' && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
