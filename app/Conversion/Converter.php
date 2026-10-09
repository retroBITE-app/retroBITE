<?php

declare(strict_types=1);

namespace App\Conversion;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * One format conversion, whole: its manifest and how to run it.
 *
 * Self-contained the way a console layout is. A converter declares which
 * source formats it takes, what it writes, which options it understands, and
 * the command that does it; it is registered by key in config/converters.php.
 * Which consoles offer it is theirs to say, in their own config/consoles/
 * file's `converters`, as with `layouts`. {@see Converters} decides where it
 * is offered, and {@see ConversionRunner} runs it — neither knows anything
 * about any one tool.
 *
 * Pure: no database, and the filesystem only through the paths it is handed.
 * A converter builds a command; it never runs one.
 */
abstract class Converter
{
    /** Check what was written with the tool's own verifier before keeping it. */
    public const VERIFY = 'verify';

    /** One of {@see compressions()}. */
    public const COMPRESSION = 'compression';

    /** Leave the source where it is. Off, it is deleted once the output is in place. */
    public const KEEP_SOURCE = 'keep_source';

    /** The tool's own tunables, by {@see Setting} key; see {@see settings()}. */
    public const ADVANCED = 'advanced';

    /** Tools → Decrypt, as {@see page()} names it. */
    public const PAGE_DECRYPT = 'decrypt';

    /** What config/converters.php registers it as, and a conversion stores. */
    abstract public function key(): string;

    /** The format as the page names it, e.g. "CHD". */
    abstract public function label(): string;

    /** The key of the tool it runs, in config/converters.php `tools`. */
    abstract public function tool(): string;

    /**
     * The extensions it reads, lower case.
     *
     * @return list<string>
     */
    abstract public function from(): array;

    /** The extension it writes, lower case. */
    abstract public function to(): string;

    /**
     * The command line after the binary.
     *
     * @param  string  $input  absolute path of what {@see input()} chose
     * @param  list<string>  $outputs  absolute paths, in the order {@see outputsFor()} named them
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    abstract public function arguments(string $input, array $outputs, array $options): array;

    /**
     * Every tool it runs, for the gate: it is offered only when all are here.
     *
     * @return list<string>
     */
    public function tools(): array
    {
        return [$this->tool()];
    }

    /**
     * What runs for one disc, in order. One command by default, the tool and
     * {@see arguments()}; a converter that has to prepare its input with
     * another tool first — merging a split dump, say — returns more. Each
     * names the file it reads, which is where a silent tool's progress is
     * read from.
     *
     * @param  list<string>  $outputs  absolute paths in staging
     * @param  array<string, mixed>  $options
     * @return list<array{tool: string, arguments: list<string>, reading: string}>
     */
    public function commands(Disc $disc, string $input, array $outputs, array $options, string $root, string $staging): array
    {
        return [[
            'tool' => $this->tool(),
            'arguments' => $this->arguments($input, $outputs, $options),
            'reading' => $root.'/'.($disc->image() ?? $disc->file)->path,
        ]];
    }

    /** One line for the page: what runs, e.g. "chdman createcd". */
    public function description(): string
    {
        return $this->tool();
    }

    /**
     * The options this conversion understands. Only these are offered.
     *
     * @return list<string>
     */
    public function options(): array
    {
        return [self::KEEP_SOURCE];
    }

    /**
     * The compression levels it offers, value => label. The first is the default.
     *
     * @return array<string, string>
     */
    public function compressions(): array
    {
        return [];
    }

    /**
     * The tool's own tunables, shown under Advanced options. Each falls back
     * to its default when nothing, or nothing it offers, was chosen.
     *
     * @return list<Setting>
     */
    public function settings(): array
    {
        return [];
    }

    /** Whether it can take this particular set, beyond its extension and console. */
    public function supports(SourceSet $set): bool
    {
        return true;
    }

    /**
     * The file names one disc is written as, in the order the command takes
     * them. Named for the disc's own file.
     *
     * @return list<string>
     */
    public function outputsFor(Disc $disc): array
    {
        return [$disc->stem().'.'.$this->to()];
    }

    /**
     * The file the tool is pointed at. The disc's own file, unless the
     * converter has to write something for the tool first, into $staging.
     */
    public function input(Disc $disc, string $root, string $staging): string
    {
        return $root.'/'.$disc->file->path;
    }

    /**
     * How far through the tool is, from one line of its output: 0–100, or
     * null when the line says nothing about it. A tool that is silent when
     * piped leaves this null, and the runner reads its progress off the file
     * it is reading instead.
     */
    public function progress(string $line): ?float
    {
        return null;
    }

    /**
     * The command line, after the binary, that checks one written file; null
     * when this conversion has no way to.
     *
     * @return list<string>|null
     */
    public function verifyArguments(string $output): ?array
    {
        return null;
    }

    /**
     * Whether a verifier's output says the file is bad, for a tool that
     * exits 0 either way. Only asked of the verify step; false by default.
     */
    public function verifyFailed(string $output): bool
    {
        return false;
    }

    /**
     * Why a written file is wrong, or null: asked of every output before it reaches
     * the library, for a tool that can write a wrong file and exit 0.
     */
    public function confirm(string $output): ?string
    {
        return null;
    }

    /**
     * Where its tools run, absolute; null for wherever the worker is. For a
     * tool that writes beside itself, such as a log folder.
     */
    public function workingDirectory(string $staging): ?string
    {
        return null;
    }

    /**
     * Whether the output takes its source's place and name, its row kept;
     * keep-source does not apply. Single-file discs only.
     */
    public function replacesSource(): bool
    {
        return false;
    }

    /**
     * Files beside a disc that belong to the source rather than the output,
     * relative to the library root, and go when it is replaced.
     *
     * @return list<string>
     */
    public function companions(Disc $disc, string $root): array
    {
        return [];
    }

    /**
     * The flags whose value is a secret, such as a disc key: the command is
     * written into the conversion's log, which the queue shows, and the value
     * after each of these is masked there. The tool is still given it.
     *
     * @return list<string>
     */
    public function secretFlags(): array
    {
        return [];
    }

    /**
     * The Tools page it lives on instead of the Conversion picker, e.g.
     * {@see PAGE_DECRYPT}; null for the picker.
     */
    public function page(): ?string
    {
        return null;
    }

    /**
     * Which of a disc's outputs a playlist lists, by name.
     *
     * @param  list<string>  $outputs
     */
    public function playlistEntry(array $outputs): string
    {
        return (string) Arr::first($outputs, null, '');
    }

    /**
     * The compression level asked for, or the default when it is not one on
     * offer: what a queued conversion stores, and what its command reads.
     *
     * @param  array<string, mixed>  $options
     */
    public function compression(array $options): string
    {
        $levels = array_keys($this->compressions());
        $chosen = (string) Arr::get($options, self::COMPRESSION, '');

        return in_array($chosen, $levels, true) ? $chosen : (string) Arr::first($levels, null, '');
    }

    /**
     * One advanced setting's value as it was queued, or its default: never
     * anything the setting does not list.
     *
     * @param  array<string, mixed>  $options
     */
    protected function setting(array $options, string $key): string
    {
        $setting = collect($this->settings())->firstWhere('key', $key);

        return $setting instanceof Setting ? $setting->resolve(Arr::get($options, self::ADVANCED.'.'.$key)) : '';
    }

    /** A percentage out of a line like "Compressing, 45.2% complete...", or null. */
    protected static function percentIn(string $line, string $pattern = '/(\d+(?:\.\d+)?)%/'): ?float
    {
        $match = Str::match($pattern, $line);

        return $match !== '' ? min(100.0, (float) $match) : null;
    }
}
