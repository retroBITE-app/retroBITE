<?php

declare(strict_types=1);

namespace App\Conversion\Converters;

use App\Conversion\Converter;
use App\Conversion\Disc;
use App\Enums\ConversionFailure;
use App\Exceptions\ConversionFailed;

/**
 * A POPStarter VCD back to a cue sheet and one BIN: `pops2cue`.
 *
 * pops2cue takes no output path: it writes the two files beside the VCD it
 * is given, named for it — and only reads the name right when it ends in an
 * uppercase ".VCD". So it is given a link in staging, named that way,
 * pointing at the library's file: its output lands in staging, and the link
 * goes with the staging folder while what it points at stays.
 */
final class VcdToCue extends Converter
{
    public function key(): string
    {
        return 'vcd-to-cue';
    }

    public function label(): string
    {
        return 'BIN/CUE';
    }

    public function description(): string
    {
        return 'pops2cue';
    }

    public function tool(): string
    {
        return 'pops2cue';
    }

    /** @return list<string> */
    public function from(): array
    {
        return ['vcd'];
    }

    public function to(): string
    {
        return 'cue';
    }

    /** @return list<string> */
    public function outputsFor(Disc $disc): array
    {
        return [$disc->stem().'.cue', $disc->stem().'.bin'];
    }

    public function input(Disc $disc, string $root, string $staging): string
    {
        $link = $staging.'/'.$disc->stem().'.VCD';

        if (is_link($link)) {
            unlink($link);
        }

        // A library on a filesystem without symbolic links (exFAT, some
        // network shares) cannot take this one; say so rather than fail on
        // an unexplained error.
        if (! @symlink($root.'/'.$disc->file->path, $link)) {
            throw ConversionFailed::because(ConversionFailure::Unwritable, 'symlink in staging');
        }

        return $link;
    }

    /**
     * @param  list<string>  $outputs
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    public function arguments(string $input, array $outputs, array $options): array
    {
        return [$input];
    }
}
