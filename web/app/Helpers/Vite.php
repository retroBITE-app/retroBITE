<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Generates <script> and <link> tags for Vite assets.
 *
 * Dev mode  — no manifest present — injects the Vite dev server client.
 * Prod mode — reads the Vite manifest to inject hashed asset paths.
 */
class Vite
{
    private static ?array $manifest = null;

    /**
     * A build fingerprint for Inertia's asset-version handshake: the manifest's
     * hash in production, a constant in dev where HMR already reloads.
     */
    public static function version(): string
    {
        $manifest = self::manifest();

        return $manifest === null ? 'dev' : md5(json_encode($manifest, JSON_THROW_ON_ERROR));
    }

    /**
     * The Vite manifest, or null in dev where no build exists.
     */
    private static function manifest(): ?array
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }

        // Vite 5+ writes to .vite/manifest.json; Vite 4 to manifest.json
        $build = config('settings.build_path');

        $candidates = [
            $build . '/.vite/manifest.json',
            $build . '/manifest.json',
        ];

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                self::$manifest = json_decode(
                    file_get_contents($path),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
                return self::$manifest;
            }
        }

        return null;
    }

    /**
     * @param string[] $entrypoints e.g. ['resources/js/app.ts']
     */
    public static function assets(array $entrypoints): string
    {
        $manifest = self::manifest();

        // Development — no manifest, use Vite HMR dev server
        if ($manifest === null) {
            $dev = rtrim((string) config('settings.vite_dev_url'), '/');

            $tags = sprintf(
                '<script type="module" src="%s/@vite/client"></script>' . "\n",
                htmlspecialchars($dev, ENT_QUOTES, 'UTF-8')
            );
            foreach ($entrypoints as $ep) {
                $tags .= sprintf(
                    '<script type="module" src="%s/%s"></script>' . "\n",
                    htmlspecialchars($dev, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($ep, ENT_QUOTES, 'UTF-8')
                );
            }
            return $tags;
        }

        // Production — resolve through manifest
        $tags = '';
        foreach ($entrypoints as $ep) {
            $entry = $manifest[$ep] ?? null;
            if ($entry === null) {
                continue;
            }
            foreach ($entry['css'] ?? [] as $css) {
                $tags .= sprintf('<link rel="stylesheet" href="/build/%s">' . "\n", htmlspecialchars($css, ENT_QUOTES, 'UTF-8'));
            }
            $tags .= sprintf(
                '<script type="module" src="/build/%s"></script>' . "\n",
                htmlspecialchars($entry['file'], ENT_QUOTES, 'UTF-8')
            );
        }

        return $tags;
    }
}
