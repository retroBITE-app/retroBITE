<?php

declare(strict_types=1);

namespace App\Conversion\Converters;

use App\Conversion\Setting;
use App\Conversion\SourceSet;

/**
 * A DVD image to CHD: `chdman createdvd`. PS2 DVD titles, which PCSX2 reads
 * as CHD directly. An .img of 2352-byte sectors is a CD and goes through
 * createcd instead.
 */
final class CreateDvdChd extends ChdmanConverter
{
    public function key(): string
    {
        return 'chd-dvd';
    }

    public function label(): string
    {
        return 'CHD';
    }

    public function description(): string
    {
        return 'chdman createdvd';
    }

    /** @return list<string> */
    public function from(): array
    {
        return ['iso', 'img'];
    }

    public function to(): string
    {
        return 'chd';
    }

    /** @return list<string> */
    public function options(): array
    {
        return [self::VERIFY, self::COMPRESSION, self::KEEP_SOURCE];
    }

    /** @return array<string, string> */
    public function compressions(): array
    {
        return ['default' => __('Best (lzma, zlib, huffman, flac)'), 'fast' => __('Fast (zlib)')];
    }

    /** @return list<Setting> */
    public function settings(): array
    {
        return [
            new Setting(
                key: 'hunk',
                label: __('Hunk size (-hs)'),
                description: __('Bytes compressed as one unit, in whole 2,048-byte sectors. 4,096 is chdman\'s default; PPSSPP only reads 2,048.'),
                default: '4096',
                choices: ['2048' => __('2,048 (1 sector)'), '4096' => __('4,096 (chdman default)'), '8192' => '8,192', '16384' => '16,384'],
            ),
            $this->processors(),
        ];
    }

    public function supports(SourceSet $set): bool
    {
        foreach ($set->discs as $disc) {
            $bytes = (int) $disc->file->size_bytes;

            if ($disc->extension() === 'img' && self::rawSectors($bytes)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $outputs
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    public function arguments(string $input, array $outputs, array $options): array
    {
        $arguments = ['createdvd', '-i', $input, '-o', $outputs[0], ...$this->tuning($options)];

        if ($this->compression($options) === 'fast') {
            array_push($arguments, '-c', 'zlib');
        }

        return $arguments;
    }

    /** @return list<string> */
    public function verifyArguments(string $output): array
    {
        return ['verify', '-i', $output];
    }
}
