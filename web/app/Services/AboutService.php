<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use PDO;
use Throwable;

/**
 * The About tab's content: what config/about.php declares, plus what only the
 * running host can answer.
 */
class AboutService
{
    /**
     * @return array{name: string, version: string, summary: array<int, string>, build: array<int, array{key: string, value: string}>, links: array<int, array<string, string>>, credits: array<int, array<string, string>>}
     */
    public function payload(): array
    {
        return [
            'name'    => (string) config('about.name'),
            'version' => (string) config('about.version'),
            'summary' => (array) config('about.summary', []),
            'build'   => $this->buildRows(),
            'links'   => (array) config('about.links', []),
            'credits' => (array) config('about.credits', []),
        ];
    }

    /**
     * What this install is actually running on.
     *
     * @return array<int, array{key: string, value: string}>
     */
    private function buildRows(): array
    {
        return [
            ['key' => 'Runtime', 'value' => 'php ' . PHP_VERSION],
            ['key' => 'Platform', 'value' => $this->platform()],
            ['key' => 'Database', 'value' => 'sqlite ' . $this->sqliteVersion()],
        ];
    }

    /**
     * Hosting and architecture, e.g. "docker · linux/x86_64".
     */
    private function platform(): string
    {
        $hosting = file_exists('/.dockerenv') ? 'docker' : 'native';

        return sprintf('%s · %s/%s', $hosting, strtolower(PHP_OS_FAMILY), php_uname('m'));
    }

    /**
     * The SQLite build behind the connection, or "unknown" when no database has
     * been booted — the About tab renders either way.
     */
    private function sqliteVersion(): string
    {
        try {
            return (string) Capsule::connection()->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (Throwable) {
            return 'unknown';
        }
    }
}
