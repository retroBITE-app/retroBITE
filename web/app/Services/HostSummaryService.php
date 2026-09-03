<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\GameRepository;
use App\Support\Console;

/**
 * Whole-host figures for the login page's art pane.
 */
class HostSummaryService
{
    public function __construct(
        private GameRepository $games,
    ) {}

    /**
     * Games catalogued, consoles installed and disk used — or null when the
     * `login_show_stats` setting is off.
     *
     * These land on an unauthenticated page, so the setting exists to withhold
     * them; see config/settings.php.
     *
     * @return array<int, array{value: string, label: string}>|null
     */
    public function loginSummary(): ?array
    {
        if (!config('settings.login_show_stats')) {
            return null;
        }

        $library  = $this->games->librarySummary();
        $consoles = Console::allInstalled()->count();
        $used     = $this->formatBytes($library['bytes']);
        $total    = $this->diskTotal();

        return [
            [
                'value' => number_format($library['game_count']),
                'label' => $this->plural($library['game_count'], 'game catalogued', 'games catalogued'),
            ],
            [
                'value' => (string) $consoles,
                'label' => $this->plural($consoles, 'console installed', 'consoles installed'),
            ],
            [
                'value' => $used,
                'label' => $total === null ? 'of library on disk' : "of {$total} used",
            ],
        ];
    }

    /**
     * Pick the singular or plural label for a count.
     */
    private function plural(int $count, string $one, string $many): string
    {
        return $count === 1 ? $one : $many;
    }

    /**
     * Capacity of the volume holding the library, or null when it cannot be read.
     */
    private function diskTotal(): ?string
    {
        $path  = (string) config('settings.games_path');
        $bytes = is_dir($path) ? @disk_total_space($path) : false;

        return $bytes === false ? null : $this->formatBytes((int) $bytes);
    }

    /**
     * Bytes as a short human figure, e.g. "412 GB".
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $value = (float) max(0, $bytes);
        $unit  = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return $unit <= 1
            ? sprintf('%d %s', round($value), $units[$unit])
            : sprintf('%s %s', rtrim(rtrim(number_format($value, 1), '0'), '.'), $units[$unit]);
    }
}
