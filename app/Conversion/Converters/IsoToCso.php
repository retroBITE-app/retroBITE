<?php

declare(strict_types=1);

namespace App\Conversion\Converters;

use App\Conversion\Setting;

/** An ISO to CSO, deflate-compressed: `maxcso`. Read by Open PS2 Loader 1.2+ and PCSX2. */
class IsoToCso extends MaxcsoConverter
{
    public function key(): string
    {
        return 'cso';
    }

    public function label(): string
    {
        return 'CSO';
    }

    public function description(): string
    {
        return 'maxcso';
    }

    /** @return list<string> */
    public function from(): array
    {
        return ['iso'];
    }

    public function to(): string
    {
        return 'cso';
    }

    /** @return list<string> */
    public function options(): array
    {
        return [self::COMPRESSION, self::KEEP_SOURCE];
    }

    /** @return array<string, string> */
    public function compressions(): array
    {
        return ['default' => __('Default'), 'fast' => __('Fast'), 'best' => __('Smallest (zopfli, slow)')];
    }

    /**
     * Block size first: 2,048 bytes is one DVD sector, and what Open PS2
     * Loader and PCSX2 read everywhere; larger is for testing on hardware
     * that reads faster aligned that way. Then the threads.
     *
     * @return list<Setting>
     */
    public function settings(): array
    {
        return [
            new Setting(
                key: 'block',
                label: __('Block size (--block)'),
                description: __('Bytes compressed as one block. 2,048 is one sector and reads everywhere; larger blocks may read faster on some hardware but are less widely supported.'),
                default: '2048',
                choices: ['2048' => __('2,048 (most compatible)'), '4096' => '4,096', '8192' => '8,192'],
            ),
            $this->threads(),
        ];
    }

    /**
     * @param  list<string>  $outputs
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    public function arguments(string $input, array $outputs, array $options): array
    {
        return [
            ...$this->format(),
            '--block='.$this->setting($options, 'block'),
            ...$this->level($options),
            ...$this->threadArguments($options),
            $input,
            '-o',
            $outputs[0],
        ];
    }

    /** @return list<string> */
    protected function format(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    protected function level(array $options): array
    {
        return match ($this->compression($options)) {
            'fast' => ['--fast'],
            'best' => ['--use-zopfli'],
            default => [],
        };
    }
}
