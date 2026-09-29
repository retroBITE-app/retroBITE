<?php

declare(strict_types=1);

namespace App\Conversion\Converters;

use App\Conversion\Setting;

/**
 * A GameCube or Wii disc to RVZ: `nodtool convert`. Dolphin's own format and
 * the RetroArch Dolphin core's: lossless, Zstandard-compressed, and read
 * back to a plain ISO that matches its Redump hash.
 */
final class ToRvz extends NodtoolConverter
{
    public function key(): string
    {
        return 'rvz';
    }

    public function label(): string
    {
        return 'RVZ';
    }

    public function description(): string
    {
        return 'nodtool convert';
    }

    /** @return list<string> */
    public function from(): array
    {
        return ['iso', 'gcm', 'wia', 'wbfs', 'ciso', 'gcz'];
    }

    public function to(): string
    {
        return 'rvz';
    }

    /** @return list<Setting> */
    public function settings(): array
    {
        return [
            new Setting(
                key: 'level',
                label: __('Zstandard level (-c zstd:N)'),
                description: __('Higher is smaller and slower. 5 is what Dolphin suggests; 19, nodtool\'s own default, takes many times as long for a few percent more.'),
                default: '5',
                choices: ['5' => __('5 (fast, Dolphin\'s suggestion)'), '10' => '10', '19' => __('19 (small, slow)'), '22' => __('22 (smallest, slowest)')],
            ),
        ];
    }

    /**
     * @param  list<string>  $outputs
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    public function arguments(string $input, array $outputs, array $options): array
    {
        return ['convert', '-c', 'zstd:'.$this->setting($options, 'level'), $input, $outputs[0]];
    }
}
