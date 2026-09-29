<?php

declare(strict_types=1);

namespace App\Conversion\Converters;

use App\Conversion\SourceSet;

/**
 * A DVD inside a CHD back out to an ISO: `chdman extractdvd`. For Open PS2
 * Loader, which reads ISO and CSO/ZSO but not CHD.
 */
final class ChdToIso extends ChdmanConverter
{
    public function key(): string
    {
        return 'chd-to-iso';
    }

    public function label(): string
    {
        return 'ISO';
    }

    public function description(): string
    {
        return 'chdman extractdvd';
    }

    /** @return list<string> */
    public function from(): array
    {
        return ['chd'];
    }

    public function to(): string
    {
        return 'iso';
    }

    public function supports(SourceSet $set): bool
    {
        return self::allOfKind($set, self::root(), 'dvd');
    }

    /**
     * @param  list<string>  $outputs
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    public function arguments(string $input, array $outputs, array $options): array
    {
        return ['extractdvd', '-i', $input, '-o', $outputs[0]];
    }
}
