<?php

declare(strict_types=1);

namespace App\Conversion\Converters;

use App\Conversion\Converter;
use App\Conversion\Disc;
use App\Conversion\Setting;
use App\Conversion\SourceSet;
use App\Enums\FileRole;
use App\Models\GameFile;
use App\Support\LibraryPath;
use Illuminate\Support\Str;

/**
 * A PS1 cue sheet to a POPStarter VCD: `cue2pops`, so a PS2 runs the game.
 *
 * cue2pops is strict: one BIN per sheet, its name in quotes, and
 * `TRACK 01 MODE2/2352` exactly. A sheet that is not already that — a Redump
 * dump split into a BIN per track, above all — is first put through chdman
 * in staging, createcd then extractcd, which merges the tracks into one BIN
 * behind a sheet written the way cue2pops reads. The disc itself is copied
 * into the VCD unchanged, behind a 1 MiB header.
 *
 * Written beside the source with an uppercase .VCD, which POPStarter
 * requires; it goes into the PS2's POPS/ folder from there.
 */
final class CueToVcd extends Converter
{
    /** The largest sheet worth reading; a real one is a few hundred bytes. */
    private const SHEET_LIMIT = 65536;

    public function key(): string
    {
        return 'vcd';
    }

    public function label(): string
    {
        return 'VCD';
    }

    public function description(): string
    {
        return 'cue2pops';
    }

    public function tool(): string
    {
        return 'cue2pops';
    }

    /** @return list<string> */
    public function tools(): array
    {
        return ['cue2pops', 'chdman'];
    }

    /** @return list<string> */
    public function from(): array
    {
        return ['cue'];
    }

    public function to(): string
    {
        return 'vcd';
    }

    /** @return list<Setting> */
    public function settings(): array
    {
        return [
            new Setting(
                key: 'gap',
                label: __('Track gaps'),
                description: __('Moves every track index by two seconds, for the few dumps whose CD audio starts early or late on POPStarter.'),
                default: 'none',
                choices: ['none' => __('As dumped'), 'plus' => __('Two seconds later (gap++)'), 'minus' => __('Two seconds earlier (gap--)')],
            ),
            new Setting(
                key: 'vmode',
                label: __('PAL to NTSC patch (vmode)'),
                description: __('Patches a PAL game to run at 60 Hz and moves the picture to fit. Not every game takes it.'),
                default: 'off',
                choices: ['off' => __('Off'), 'on' => __('On')],
            ),
            new Setting(
                key: 'trainer',
                label: __('Cheats (trainer)'),
                description: __('Applies cue2pops\' built-in cheats, for the handful of games it has any for.'),
                default: 'off',
                choices: ['off' => __('Off'), 'on' => __('On')],
            ),
        ];
    }

    /** Only a data disc of raw sectors, and no sheet that points at WAV audio. */
    public function supports(SourceSet $set): bool
    {
        foreach ($set->discs as $disc) {
            $sheet = self::sheet($disc);

            if ($sheet === null || Str::isMatch('/^\s*FILE\s+.*\s(WAVE|MP3|AIFF)\s*$/mi', $sheet)) {
                return false;
            }

            if (Str::upper(Str::match('/^\s*TRACK\s+\d+\s+(\S+)/mi', $sheet)) !== 'MODE2/2352') {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    public function outputsFor(Disc $disc): array
    {
        return [$disc->stem().'.VCD'];
    }

    /**
     * cue2pops alone for a sheet it reads as it is; chdman first for any
     * other, to merge the tracks into one BIN.
     *
     * @param  list<string>  $outputs
     * @param  array<string, mixed>  $options
     * @return list<array{tool: string, arguments: list<string>, reading: string}>
     */
    public function commands(Disc $disc, string $input, array $outputs, array $options, string $root, string $staging): array
    {
        $tracks = array_values(array_filter($disc->members, function (GameFile $member): bool {
            return $member->role === FileRole::Track;
        }));
        $image = $tracks !== [] ? $root.'/'.$tracks[0]->path : $input;

        if (self::strict((string) self::sheet($disc))) {
            return [$this->cue2pops($input, $outputs[0], $image, $options)];
        }

        // Named for the disc: chdman will not write over a file, and the
        // next disc of a set merges in the same folder.
        $chd = $staging.'/'.$disc->stem().'.merge.chd';
        $sheet = $staging.'/'.$disc->stem().'.merged.cue';
        $merged = $staging.'/'.$disc->stem().'.merged.bin';

        return [
            ['tool' => 'chdman', 'arguments' => ['createcd', '-i', $input, '-o', $chd], 'reading' => $image],
            ['tool' => 'chdman', 'arguments' => ['extractcd', '-i', $chd, '-o', $sheet, '-ob', $merged], 'reading' => $chd],
            $this->cue2pops($sheet, $outputs[0], $merged, $options),
        ];
    }

    /**
     * @param  list<string>  $outputs
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    public function arguments(string $input, array $outputs, array $options): array
    {
        return [$input, ...$this->flags($options), $outputs[0]];
    }

    /** chdman's own progress, while it merges; cue2pops prints none. */
    public function progress(string $line): ?float
    {
        return Str::contains($line, '% complete') ? self::percentIn($line) : null;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{tool: string, arguments: list<string>, reading: string}
     */
    private function cue2pops(string $sheet, string $output, string $image, array $options): array
    {
        return ['tool' => 'cue2pops', 'arguments' => $this->arguments($sheet, [$output], $options), 'reading' => $image];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    private function flags(array $options): array
    {
        $flags = match ($this->setting($options, 'gap')) {
            'plus' => ['gap++'],
            'minus' => ['gap--'],
            default => [],
        };

        if ($this->setting($options, 'vmode') === 'on') {
            $flags[] = 'vmode';
        }

        if ($this->setting($options, 'trainer') === 'on') {
            $flags[] = 'trainer';
        }

        return $flags;
    }

    /**
     * Whether cue2pops reads the sheet as it is: one quoted FILE, and the
     * first track written exactly as it expects.
     */
    private static function strict(string $sheet): bool
    {
        return Str::substrCount(Str::upper($sheet), 'FILE ') === 1
            && Str::isMatch('/^\s*FILE\s+"[^"]+"\s+BINARY\s*$/m', $sheet)
            && Str::isMatch('/^\s*TRACK 01 MODE2\/2352\s*$/m', $sheet);
    }

    /** The disc's cue sheet, or null when it is not a sheet or cannot be read. */
    private static function sheet(Disc $disc): ?string
    {
        if ($disc->file->role !== FileRole::Sheet) {
            return null;
        }

        $path = app(LibraryPath::class)->root().'/'.$disc->file->path;

        if (! is_file($path) || filesize($path) > self::SHEET_LIMIT) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }
}
