<?php

declare(strict_types=1);

namespace App\Conversion\Converters;

use App\Conversion\Disc;
use App\Conversion\SourceSet;

/**
 * A CD inside a CHD back out to a cue sheet and one bin: `chdman extractcd`.
 *
 * For the optical drive emulators — xStation, PSIO — and anything else that
 * reads no CHD. On a console with only CDs every CHD holds one; elsewhere
 * the CHD is asked which it holds.
 */
final class ChdToCue extends ChdmanConverter
{
    public function key(): string
    {
        return 'chd-to-cue';
    }

    public function label(): string
    {
        return 'BIN/CUE';
    }

    public function description(): string
    {
        return 'chdman extractcd';
    }

    /** @return list<string> */
    public function from(): array
    {
        return ['chd'];
    }

    public function to(): string
    {
        return 'cue';
    }

    public function supports(SourceSet $set): bool
    {
        return self::cdOnly($set->console) || self::allOfKind($set, self::root(), 'cd');
    }

    /** @return list<string> */
    public function outputsFor(Disc $disc): array
    {
        return [$disc->stem().'.cue', $disc->stem().'.bin'];
    }

    /**
     * @param  list<string>  $outputs
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    public function arguments(string $input, array $outputs, array $options): array
    {
        return ['extractcd', '-i', $input, '-o', $outputs[0], '-ob', $outputs[1]];
    }
}
