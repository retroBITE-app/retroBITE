<?php

declare(strict_types=1);

namespace App\Tools;

use App\Models\GameFile;
use App\Support\Console;
use App\Support\Layouts\ConsoleLayout;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * What one console knows about its own files that nothing general could.
 *
 * Some facts live only inside the file: a PlayStation 2 disc carries the serial
 * it names itself by, and no provider will tell you what it is. Reading that in
 * PHP means implementing ISO9660; `strings` over the first 16 MiB answers in a
 * line. So each console's quirks are a small shell library under app/Scripts,
 * and this is the seam between them and the application.
 *
 * A console without a toolbox is the ordinary case. Every caller resolves one
 * with for() and does nothing when it comes back null.
 */
abstract class ConsoleTools
{
    /**
     * Where every console's toolbox answers: app/Scripts/{KEY}/Inspect.sh.
     *
     * One name, so adding a toolbox later means writing one file at a known
     * path rather than inventing a name and wiring it up.
     */
    protected const SCRIPT = 'Inspect.sh';

    /** The config/consoles key this toolbox serves. */
    abstract public function consoleKey(): string;

    /**
     * Whether this toolbox can say anything about that file.
     *
     * The console's own toolbox_file_extensions, so what a toolbox opens is
     * declared in config beside everything else about the console rather than
     * buried in a class. Whether a particular file can really be read is still
     * the script's business: an extension is a declaration, and a compressed
     * image renamed to .iso carries a convincing one.
     */
    public function handles(GameFile $file): bool
    {
        $console = Console::tryFrom($this->consoleKey());

        return $console !== null && in_array(
            Str::lower($file->extension),
            array_map('strtolower', $console->toolboxFileExtensions),
            true,
        );
    }

    /**
     * Everything this toolbox can read out of one file.
     *
     * Keys map onto game_files columns. An empty array means nothing was
     * learned, which is an ordinary answer for a file that is not what it
     * looked like.
     *
     * @return array{license_id?: string, cover_id?: string, region?: string, video_mode?: string}
     */
    abstract public function inspect(GameFile $file): array;

    /**
     * Files this toolbox can write back into the console's folder.
     *
     * Empty here on purpose: reading is the general case and writing into
     * somebody's library is not.
     *
     * @return string[]
     */
    public function exports(): array
    {
        return [];
    }

    /**
     * Write one of the files exports() names, into the console's folder.
     *
     * Declared here so a caller can ask any toolbox for any export and be told
     * nothing happened, rather than having to know which class it is holding.
     * The base writes nothing, which is the honest answer for a console with
     * no loader to write for.
     *
     * @param  null|callable(int, int): void  $onProgress  called with (done, total) as it goes
     * @return array{written: int, skipped: int, failed: int}
     */
    public function export(Console $console, string $export, bool $force = false, ?callable $onProgress = null): array
    {
        return ['written' => 0, 'skipped' => 0, 'failed' => 0];
    }

    /**
     * This console's own reading of a filename under that layout.
     *
     * Null means no opinion, and the layout's own answer stands. The hook
     * exists for conventions that belong to a console and a layout together —
     * a PS2 serial prefix means nothing on a RetroArch drive and nothing at all
     * on a SNES one, so neither side can own it alone.
     */
    public function titleFor(ConsoleLayout $layout, string $relative): ?string
    {
        return null;
    }

    /**
     * The toolbox for a console, or null where none is registered.
     */
    public static function for(Console $console): ?self
    {
        $class = Arr::get((array) config('console_tools.consoles', []), $console->key);

        if (! is_string($class) || ! class_exists($class)) {
            return null;
        }

        $tools = app($class);

        return $tools instanceof self ? $tools : null;
    }

    /**
     * Where this console's scripts live, e.g. app/Scripts/PS2.
     */
    protected function scriptsPath(): string
    {
        return base_path('app/Scripts/'.Str::upper($this->consoleKey()));
    }

    /**
     * A file's absolute path, from the path relative to the library root.
     */
    protected function absolutePath(GameFile $file): string
    {
        return rtrim((string) config('settings.games_path'), '/').'/'.$file->path;
    }

    /**
     * Run one of this console's scripts and read its tab-separated output.
     *
     * Passed as an argument list rather than a command string, so there is no
     * shell between here and the script and nothing to escape. Exit 1 is the
     * scripts' "nothing found", which is an answer rather than a failure;
     * anything higher is logged. stderr is never returned — it carries
     * absolute container paths, and nothing that leaves this method should.
     *
     * @param  string[]  $arguments
     * @return array<string, string>
     */
    protected function run(string $script, array $arguments): array
    {
        $result = Process::path($this->scriptsPath())
            ->timeout((int) config('console_tools.timeout', 900))
            ->run(array_merge(['bash', $script], $arguments));

        if ($result->exitCode() === 1) {
            return [];
        }

        if ($result->failed()) {
            Log::warning('A console tool did not finish.', [
                'console' => $this->consoleKey(),
                'script' => $script,
                'exit_code' => $result->exitCode(),
            ]);

            return [];
        }

        return $this->parse($result->output());
    }

    /**
     * Tab-separated key/value lines into an array.
     *
     * @return array<string, string>
     */
    private function parse(string $output): array
    {
        return Collection::make(preg_split('/\R/', $output) ?: [])
            ->mapWithKeys(function (string $line): array {
                [$key, $value] = array_pad(explode("\t", $line, 2), 2, '');

                $key = trim($key);
                $value = trim($value);

                return $key !== '' && $value !== '' ? [$key => $value] : [];
            })
            ->all();
    }
}
