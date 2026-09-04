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
     * Tags for the given entrypoints — dev-server scripts when no build exists,
     * hashed asset paths from the manifest otherwise.
     *
     * @param string[] $entrypoints e.g. ['resources/js/app.ts']
     */
    public static function assets(array $entrypoints): string
    {
        $manifest = self::manifest();

        return $manifest === null
            ? self::devTags($entrypoints)
            : self::buildTags($manifest, $entrypoints);
    }

    /**
     * Script tags pointing at the Vite HMR server.
     *
     * @param string[] $entrypoints
     */
    private static function devTags(array $entrypoints): string
    {
        $dev  = rtrim((string) config('settings.vite_dev_url'), '/');
        $tags = self::script($dev . '/@vite/client');

        foreach ($entrypoints as $entrypoint) {
            $tags .= self::script($dev . '/' . $entrypoint);
        }

        return $tags;
    }

    /**
     * Stylesheet and script tags resolved through the build manifest.
     *
     * @param string[] $entrypoints
     */
    private static function buildTags(array $manifest, array $entrypoints): string
    {
        $tags = '';

        foreach ($entrypoints as $entrypoint) {
            $entry = $manifest[$entrypoint] ?? null;

            if ($entry === null) {
                continue;
            }

            foreach ($entry['css'] ?? [] as $stylesheet) {
                $tags .= self::stylesheet('/build/' . $stylesheet);
            }

            $tags .= self::script('/build/' . $entry['file']);
        }

        return $tags;
    }

    /**
     * A module script tag with the src escaped.
     */
    private static function script(string $src): string
    {
        return sprintf(
            '<script type="module" src="%s"></script>' . "\n",
            htmlspecialchars($src, ENT_QUOTES, 'UTF-8'),
        );
    }

    /**
     * A stylesheet link tag with the href escaped.
     */
    private static function stylesheet(string $href): string
    {
        return sprintf(
            '<link rel="stylesheet" href="%s">' . "\n",
            htmlspecialchars($href, ENT_QUOTES, 'UTF-8'),
        );
    }
}
