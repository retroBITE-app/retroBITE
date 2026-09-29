<?php

declare(strict_types=1);

namespace App\Conversion\Converters;

/**
 * A GameCube or Wii disc back to a plain ISO: `nodtool convert`. From RVZ,
 * WIA, WBFS, CISO or GCZ; the padding NKit-lossless formats leave out is
 * rebuilt, so the ISO matches the disc's Redump hash. The plain ISO is what
 * USB Loader GX, WiiFlow, Nintendont and Dolphin all read.
 */
final class NodToIso extends NodtoolConverter
{
    public function key(): string
    {
        return 'nod-to-iso';
    }

    public function label(): string
    {
        return 'ISO';
    }

    public function description(): string
    {
        return __('nodtool convert — USB Loader GX, WiiFlow, Nintendont, Dolphin');
    }

    /** @return list<string> */
    public function from(): array
    {
        return ['rvz', 'wia', 'wbfs', 'ciso', 'gcz'];
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
        return ['convert', $input, $outputs[0]];
    }
}
