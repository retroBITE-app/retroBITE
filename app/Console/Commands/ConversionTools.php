<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Conversion\Converters;
use App\Conversion\Tools;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * Which conversion tools are installed, and which converters that leaves.
 *
 * Run by both web entrypoints at boot, so the container log says straight
 * away when an image was built without a tool. Never fails the boot: a
 * missing tool only disables the converters that need it.
 */
class ConversionTools extends Command
{
    protected $signature = 'conversion:tools';

    protected $description = 'Check the conversion tools';

    public function handle(): int
    {
        Tools::forget();

        $report = Tools::report();

        $this->table(
            ['Tool', 'Looked for', 'Found at', 'Pinned', 'Answers as'],
            array_map(function (array $tool): array {
                return [
                    Arr::get($tool, 'label'),
                    Arr::get($tool, 'configured'),
                    Arr::get($tool, 'path') ?? 'missing',
                    Arr::get($tool, 'pinned'),
                    Arr::get($tool, 'found') ?? '—',
                ];
            }, $report),
        );

        foreach (Converters::all() as $converter) {
            $missing = Tools::missing($converter);

            if ($missing !== null) {
                $this->components->warn(sprintf('%s (%s) is off: %s is missing.', $converter->label(), $converter->key(), $missing));
            }
        }

        foreach ($report as $tool) {
            $found = Arr::get($tool, 'found');

            if ($found !== null && $found !== Arr::get($tool, 'pinned')) {
                $this->components->warn(sprintf('%s answers as %s, not the pinned %s.', Arr::get($tool, 'label'), $found, Arr::get($tool, 'pinned')));
            }
        }

        return self::SUCCESS;
    }
}
