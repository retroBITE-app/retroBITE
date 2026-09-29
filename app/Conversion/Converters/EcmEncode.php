<?php

declare(strict_types=1);

namespace App\Conversion\Converters;

use App\Conversion\Converter;
use App\Conversion\Disc;
use App\Conversion\SourceSet;

/**
 * A raw CD image to ECM: `ecm`.
 *
 * ECM drops the error-correction data every raw 2352-byte CD sector carries
 * and a reader can compute again, which is what it saves. A DVD image of
 * 2048-byte sectors carries none, so this is PS1 only. It reads one image, so
 * a sheet of several tracks is not offered; a single-track sheet's image is
 * what goes in. The output is named for the image, "Game.bin.ecm", which is
 * what DuckStation and unecm expect.
 */
final class EcmEncode extends Converter
{
    public function key(): string
    {
        return 'ecm';
    }

    public function label(): string
    {
        return 'ECM';
    }

    public function tool(): string
    {
        return 'ecm';
    }

    /** @return list<string> */
    public function from(): array
    {
        return ['cue', 'bin', 'img', 'iso'];
    }

    public function to(): string
    {
        return 'ecm';
    }

    public function supports(SourceSet $set): bool
    {
        foreach ($set->discs as $disc) {
            if ($disc->image() === null) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    public function outputsFor(Disc $disc): array
    {
        return [($disc->image() ?? $disc->file)->filename.'.ecm'];
    }

    public function input(Disc $disc, string $root, string $staging): string
    {
        return $root.'/'.($disc->image() ?? $disc->file)->path;
    }

    /**
     * @param  list<string>  $outputs
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    public function arguments(string $input, array $outputs, array $options): array
    {
        return [$input, $outputs[0]];
    }
}
