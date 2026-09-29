<?php

declare(strict_types=1);

namespace App\Conversion\Converters;

/** A CSO or ZSO back to a plain ISO: `maxcso --decompress`. */
final class CsoToIso extends MaxcsoConverter
{
    public function key(): string
    {
        return 'cso-to-iso';
    }

    public function label(): string
    {
        return 'ISO';
    }

    public function description(): string
    {
        return 'maxcso --decompress';
    }

    /** @return list<string> */
    public function from(): array
    {
        return ['cso', 'zso'];
    }

    public function to(): string
    {
        return 'iso';
    }

    /**
     * @param  list<string>  $outputs
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    public function arguments(string $input, array $outputs, array $options): array
    {
        return ['--decompress', ...$this->threadArguments($options), $input, '-o', $outputs[0]];
    }
}
