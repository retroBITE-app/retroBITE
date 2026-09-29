<?php

declare(strict_types=1);

namespace App\Conversion\Converters;

use App\Conversion\Disc;
use App\Conversion\Setting;
use App\Conversion\SourceSet;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * A CD image to CHD: `chdman createcd`.
 *
 * Takes a cue sheet as it is. A bare image with no sheet — a .bin, an .img, a
 * PS1 .iso — gets one written for it in staging, one data track sized by the
 * image: 2352-byte raw sectors as MODE2/2352, which is what a PlayStation
 * disc's are, and 2048-byte user data as MODE1/2048 otherwise. The sheet
 * names the image by a path relative to itself, the only kind chdman follows.
 *
 * On a console that also has DVDs only the CD-sized images are offered; a
 * DVD goes through createdvd.
 */
final class CreateCdChd extends ChdmanConverter
{
    public function key(): string
    {
        return 'chd-cd';
    }

    public function label(): string
    {
        return 'CHD';
    }

    public function description(): string
    {
        return 'chdman createcd';
    }

    /** @return list<string> */
    public function from(): array
    {
        return ['cue', 'bin', 'img', 'iso'];
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
        return ['default' => __('Best (lzma, zlib, flac)'), 'fast' => __('Fast (zlib)')];
    }

    /** @return list<Setting> */
    public function settings(): array
    {
        return [
            new Setting(
                key: 'hunk',
                label: __('Hunk size (-hs)'),
                description: __('Bytes compressed as one unit, in whole CD frames of 2,448. Larger can compress a little better; 8 frames is what chdman and every emulator expect.'),
                default: '19584',
                choices: ['9792' => __('9,792 (4 frames)'), '19584' => __('19,584 (8 frames, chdman default)'), '39168' => __('39,168 (16 frames)')],
            ),
            $this->processors(),
        ];
    }

    public function supports(SourceSet $set): bool
    {
        if (self::cdOnly($set->console)) {
            return true;
        }

        foreach ($set->discs as $disc) {
            if ($disc->extension() === 'cue') {
                continue;
            }

            if ($disc->extension() === 'iso' || ! self::rawSectors((int) $disc->file->size_bytes)) {
                return false;
            }
        }

        return true;
    }

    public function input(Disc $disc, string $root, string $staging): string
    {
        $source = $root.'/'.$disc->file->path;

        if ($disc->extension() === 'cue') {
            return $source;
        }

        $size = is_file($source) ? (int) filesize($source) : 0;
        $mode = $disc->extension() !== 'iso' && self::rawSectors($size) ? 'MODE2/2352' : 'MODE1/2048';
        $sheet = $staging.'/'.$disc->stem().'.source.cue';

        File::put($sheet, 'FILE "'.self::fromStaging($root, $staging).$disc->file->path.'" BINARY'."\n  TRACK 01 ".$mode."\n    INDEX 01 00:00:00\n");

        return $sheet;
    }

    /**
     * The way back up from the staging folder to the library root, "../../".
     * Relative because chdman reads a sheet's FILE as relative to the sheet
     * whatever it says, and an absolute path there is looked for under it.
     */
    private static function fromStaging(string $root, string $staging): string
    {
        $depth = count(explode('/', trim(Str::after($staging, $root.'/'), '/')));

        return str_repeat('../', $depth);
    }

    /**
     * @param  list<string>  $outputs
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    public function arguments(string $input, array $outputs, array $options): array
    {
        $arguments = ['createcd', '-i', $input, '-o', $outputs[0], ...$this->tuning($options)];

        if ($this->compression($options) === 'fast') {
            array_push($arguments, '-c', 'cdzl');
        }

        return $arguments;
    }

    /** @return list<string> */
    public function verifyArguments(string $output): array
    {
        return ['verify', '-i', $output];
    }
}
