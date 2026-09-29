<?php

declare(strict_types=1);

namespace App\Conversion\Converters;

use App\Conversion\Converter;
use App\Conversion\Disc;
use Illuminate\Support\Str;

/**
 * ECM back to the raw image: `unecm`. "Game.bin.ecm" comes back as
 * "Game.bin"; an ".ecm" with no image extension under it comes back as a .bin.
 */
final class EcmDecode extends Converter
{
    public function key(): string
    {
        return 'unecm';
    }

    public function label(): string
    {
        return 'BIN';
    }

    public function tool(): string
    {
        return 'unecm';
    }

    /** @return list<string> */
    public function from(): array
    {
        return ['ecm'];
    }

    public function to(): string
    {
        return 'bin';
    }

    /** @return list<string> */
    public function outputsFor(Disc $disc): array
    {
        $image = Str::beforeLast($disc->file->filename, '.');

        return [Str::contains($image, '.') ? $image : $image.'.bin'];
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
