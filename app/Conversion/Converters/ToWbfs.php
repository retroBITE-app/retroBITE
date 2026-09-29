<?php

declare(strict_types=1);

namespace App\Conversion\Converters;

use App\Conversion\Setting;

/**
 * A Wii disc to WBFS: `nodtool convert`. What USB Loader GX and WiiFlow read
 * natively. nodtool writes it NKit-lossless — the disc's junk padding is left
 * out and rebuilt when it goes back to ISO — and never split, which a FAT32
 * drive would need past 4 GB and a network share does not.
 */
final class ToWbfs extends NodtoolConverter
{
    public function key(): string
    {
        return 'wbfs';
    }

    public function label(): string
    {
        return 'WBFS';
    }

    public function description(): string
    {
        return 'nodtool convert (NKit-lossless)';
    }

    /** @return list<string> */
    public function from(): array
    {
        return ['iso', 'gcm', 'wia', 'rvz', 'ciso', 'gcz'];
    }

    public function to(): string
    {
        return 'wbfs';
    }

    /** @return list<Setting> */
    public function settings(): array
    {
        return [
            new Setting(
                key: 'scrub',
                label: __('Remove update partition (--scrub)'),
                description: __('Leaves out the system-update data the disc carries, which loaders never install. Smaller, but the disc no longer matches its Redump hash, so turn Verify checksum off with it.'),
                default: 'off',
                choices: ['off' => __('Off'), 'on' => __('On')],
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
        $scrub = $this->setting($options, 'scrub') === 'on' ? ['--scrub'] : [];

        return ['convert', ...$scrub, $input, $outputs[0]];
    }
}
