<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Settings → About: what config/about.php declares, plus what only the
 * running host can answer.
 */
class AboutService
{
    /**
     * Everything the About tab renders.
     *
     * @return array{version: string, build: list<array{key: string, value: string}>, website: array{icon: string, label: string, url: string}, links: list<array{icon: string, label: string, url: string}>, credits: list<array{icon: string, name: string, role: string}>}
     */
    public function payload(): array
    {
        /** @var array{icon: string, label: string, url: string} $website */
        $website = (array) config('about.website', []);

        /** @var list<array{icon: string, label: string, url: string}> $links */
        $links = (array) config('about.links', []);

        /** @var list<array{icon: string, name: string, role: string}> $credits */
        $credits = (array) config('about.credits', []);

        return [
            'version' => (string) config('app.version'),
            'build' => $this->buildRows(),
            'website' => $website,
            'links' => $links,
            'credits' => $credits,
        ];
    }

    /**
     * What this install is actually running on. Keys are translation keys.
     *
     * @return list<array{key: string, value: string}>
     */
    private function buildRows(): array
    {
        return [
            ['key' => 'Runtime', 'value' => 'php '.PHP_VERSION],
            ['key' => 'Platform', 'value' => $this->platform()],
            ['key' => 'Database', 'value' => $this->database()],
        ];
    }

    /** Hosting and architecture, e.g. "docker · linux/x86_64". */
    private function platform(): string
    {
        $hosting = file_exists('/.dockerenv') ? 'docker' : 'native';

        return sprintf('%s · %s/%s', $hosting, strtolower(PHP_OS_FAMILY), php_uname('m'));
    }

    /**
     * The driver and server version behind the default connection, or the
     * driver alone when the server will not say — the tab renders either way.
     */
    private function database(): string
    {
        $connection = DB::connection();

        try {
            return $connection->getDriverName().' '.$connection->getServerVersion();
        } catch (Throwable) {
            return $connection->getDriverName();
        }
    }
}
