<?php

declare(strict_types=1);

namespace App\Conversion\Converters;

/**
 * An ISO to ZSO, LZ4-compressed: `maxcso --format=zso`. A little larger than
 * CSO and much quicker to read, which is what a PS2 over USB 1.1 wants.
 */
final class IsoToZso extends IsoToCso
{
    public function key(): string
    {
        return 'zso';
    }

    public function label(): string
    {
        return 'ZSO';
    }

    public function description(): string
    {
        return 'maxcso --format=zso';
    }

    public function to(): string
    {
        return 'zso';
    }

    /** @return array<string, string> */
    public function compressions(): array
    {
        return ['default' => __('Default'), 'fast' => __('Fast'), 'best' => __('Smallest (slow)')];
    }

    /** @return list<string> */
    protected function format(): array
    {
        return ['--format=zso'];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    protected function level(array $options): array
    {
        return match ($this->compression($options)) {
            'fast' => ['--fast'],
            'best' => ['--use-lz4brute'],
            default => [],
        };
    }
}
