<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\GameRepository;
use App\Support\Console;

/**
 * Whole-host figures: the login page's art pane and the sidebar's storage meter.
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
     * Library size against the volume's capacity, for the sidebar's meter.
     *
     * `total` and `percent` are null when the volume cannot be read — the caller
     * shows the figure without a bar rather than guessing a denominator.
     *
     * @param ?string $volume Path to measure; defaults to the library root.
     * @return array{used: string, total: ?string, percent: ?int}
     */
    public function storageMeter(?string $volume = null): array
    {
        $used  = $this->games->librarySummary()['bytes'];
        $total = $this->diskTotalBytes($volume);

        return [
            'used'    => $this->formatBytes($used),
            'total'   => $total === null ? null : $this->formatBytes($total),
            'percent' => $total === null || $total <= 0
                ? null
                : min(100, (int) round($used / $total * 100)),
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
        $bytes = $this->diskTotalBytes();

        return $bytes === null ? null : $this->formatBytes($bytes);
    }

    /**
     * Capacity of the volume holding the library in bytes, or null when the path
     * does not exist or the filesystem refuses to report it.
     */
    private function diskTotalBytes(?string $volume = null): ?int
    {
        $path  = $volume ?? (string) config('settings.games_path');
        $bytes = is_dir($path) ? @disk_total_space($path) : false;

        return $bytes === false ? null : (int) $bytes;
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
