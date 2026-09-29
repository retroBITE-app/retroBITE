<?php

declare(strict_types=1);

namespace App\Conversion\Converters;

use App\Conversion\Converter;
use Illuminate\Support\Str;

/**
 * What every nodtool conversion shares: GameCube and Wii discs.
 *
 * nodtool picks the format it writes from the output's extension, so the
 * output keeps its real one even in staging. It draws its progress only on a
 * terminal, so the runner reads how far into the source it has got instead.
 * Every conversion and every verify hashes the disc and checks it against
 * the Redump list built into the binary — and a hash that does not match is
 * printed as "❌ (expected: …)" with the exit code still 0, so the verify step
 * reads the output rather than trusting the exit.
 */
abstract class NodtoolConverter extends Converter
{
    public function tool(): string
    {
        return 'nodtool';
    }

    /** @return list<string> */
    public function options(): array
    {
        return [self::VERIFY, self::KEEP_SOURCE];
    }

    /** @return list<string> */
    public function verifyArguments(string $output): array
    {
        return ['verify', $output];
    }

    public function verifyFailed(string $output): bool
    {
        return Str::contains($output, '(expected: ');
    }
}
