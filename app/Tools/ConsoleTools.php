<?php

declare(strict_types=1);

namespace App\Tools;

use App\Models\Game;
use App\Models\GameFile;
use App\Support\Console;
use App\Support\Layouts\ConsoleLayout;
use App\Support\Scanning\LibraryFolders;
use Closure;
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
 *
 * Built by chaining, and everything the work needs is declared here rather than
 * threaded through argument lists:
 *
 *     ConsoleTools::for($console)->export('cfg')->force()->onProgress($fn)->run();
 *
 * The setters mutate rather than clone, which is safe because for() hands back
 * a fresh instance every time — the sharing is closed at the one boundary that
 * could leak it, and an idiom that returned copies would only pretend two
 * chains off the same variable were independent.
 */
abstract class ConsoleTools
{
    /** The config/consoles key the registry filed this toolbox under. Set by for(). */
    public readonly string $consoleKey;

    /** The console this toolbox is working on. Set by for(). */
    public readonly Console $console;

    /** Where this console's scripts live, e.g. /app/app/Scripts/PS2. Set by for(). */
    protected readonly string $scriptsPath;

    /** The one entry point every toolbox answers on, from config. Set by for(). */
    protected readonly string $script;

    /** Seconds one script may run for. Set by for(). */
    protected readonly int $timeout;

    /** Which of exports() run() will write. Empty until export() names one. */
    protected string $export = '';

    /** Whether run() rewrites files that are already there. */
    protected bool $force = false;

    /** Called with (done, total) as run() goes, or null when nobody is watching. */
    protected ?Closure $onProgress = null;

    /** The game the current write is about. Set by run(), read by the writers. */
    protected ?Game $game = null;

    /** How many games the current run has been past, and how many there are. */
    protected int $done = 0;

    protected int $total = 0;

    /** The file the current inspection is about. Set by inspect(). */
    protected ?GameFile $file = null;

    /**
     * The toolbox for a console, ready to be told what to do, or null where
     * none is registered.
     */
    public static function for(Console $console): ?self
    {
        $class = Arr::get((array) config('console_tools.consoles', []), $console->key);

        if (! is_string($class) || ! class_exists($class)) {
            return null;
        }

        $tools = app($class);

        if (! $tools instanceof self) {
            Log::warning('A console_tools entry is not a toolbox.', [
                'console' => $console->key,
                'class' => $class,
            ]);

            return null;
        }

        // Cloned rather than handed straight back: the chain carries per-call
        // state on the instance, so a binding that ever became a singleton
        // would put two exports in one request on the same object.
        $tools = clone $tools;

        // Written here rather than in a constructor, because tests/Unit does
        // not boot the application and `new PS2` has to keep working without
        // one. A readonly property may be initialised anywhere in its
        // declaring class's scope, and this is the only place that does it.
        $tools->consoleKey = $console->key;
        $tools->console = $console;
        $tools->scriptsPath = base_path(
            trim((string) config('console_tools.scripts_path', 'app/Scripts'), '/')
            .'/'.Str::upper($console->key),
        );
        $tools->script = (string) config('console_tools.script', 'Inspect.sh');
        $tools->timeout = (int) config('console_tools.timeout', 900);

        return $tools;
    }

    /** Which of exports() run() will write. */
    public function export(string $export): static
    {
        $this->export = $export;

        return $this;
    }

    /** Rewrite what is already there, rather than stepping over it. */
    public function force(bool $force = true): static
    {
        $this->force = $force;

        return $this;
    }

    /**
     * Watch the export go past.
     *
     * @param  callable(int, int): void  $onProgress  called with (done, total)
     */
    public function onProgress(callable $onProgress): static
    {
        $this->onProgress = Closure::fromCallable($onProgress);

        return $this;
    }

    /**
     * Write the chosen export into the console's folder.
     *
     * Zeros here, which is the honest answer for a console with no loader to
     * write for. Declared so a caller can ask any toolbox for any export and
     * be told nothing happened, rather than having to know what it is holding.
     *
     * @return array{written: int, skipped: int, failed: int}
     */
    public function run(): array
    {
        return ['written' => 0, 'skipped' => 0, 'failed' => 0];
    }

    /**
     * Whether this console's folder is arranged the way this toolbox writes for.
     *
     * True here: a toolbox that writes nothing cannot write it into the wrong
     * shape. The menu asks before it offers an export, so the one class that
     * knows which arrangement it needs is the one that answers.
     */
    public function canExport(): bool
    {
        return true;
    }

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
        return in_array(
            Str::lower($file->extension),
            array_map('strtolower', $this->console->toolboxFileExtensions),
            true,
        );
    }

    /**
     * Everything this toolbox can read out of one file.
     *
     * Keys map onto game_files columns. An empty array means nothing was
     * learned, which is an ordinary answer for a file that is not what it
     * looked like. Takes the file rather than reading one off the chain
     * because it is a public entry point and its caller has just asked
     * handles() about the same file.
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
     * This console's own reading of a filename under that layout.
     *
     * Null means no opinion, and the layout's own answer stands. The hook
     * exists for conventions that belong to a console and a layout together —
     * a PS2 serial prefix means nothing on a RetroArch drive and nothing at all
     * on a SNES one, so neither side can own it alone.
     *
     * Stateless, and takes both: the scanner calls it once per file and holds
     * one toolbox for the whole walk.
     */
    public function titleFor(ConsoleLayout $layout, string $relative): ?string
    {
        return null;
    }

    /** Whether this console's files can be renamed to or from a loader's convention here. */
    public function canRename(): bool
    {
        return false;
    }

    /**
     * What a file would be called with the console's identifier added to its
     * name, or taken out of it; null when there is nothing to do.
     */
    public function renamedFilename(GameFile $file, bool $withLicenseId): ?string
    {
        return null;
    }

    /**
     * Tell whoever is watching how far the current run has got.
     *
     * Reads the counters off the chain rather than taking them, so a progress
     * report cannot disagree with the run it is reporting on.
     */
    protected function report(): void
    {
        if ($this->onProgress !== null) {
            ($this->onProgress)($this->done, $this->total);
        }
    }

    /**
     * The current file's absolute path, from the path relative to the library.
     */
    protected function absolutePath(): string
    {
        return LibraryFolders::root().'/'.($this->file->path ?? '');
    }

    /**
     * Run this console's script over the current file and read its output.
     *
     * Passed as an argument list rather than a command string, so there is no
     * shell between here and the script and nothing to escape. Exit 1 is the
     * scripts' "nothing found", which is an answer rather than a failure;
     * anything higher is logged. stderr is never returned — it carries
     * absolute container paths, and nothing that leaves this method should.
     *
     * Takes nothing: the script, its directory, its timeout and its one
     * argument are all on the chain. A toolbox that ever needs a second
     * argument should declare it as a property rather than reopen this
     * signature.
     *
     * @return array<string, string>
     */
    protected function runScript(): array
    {
        $result = Process::path($this->scriptsPath)
            ->timeout($this->timeout)
            ->run(['bash', $this->script, $this->absolutePath()]);

        if ($result->exitCode() === 1) {
            return [];
        }

        if ($result->failed()) {
            Log::warning('A console tool did not finish.', [
                'console' => $this->consoleKey,
                'script' => $this->script,
                'exit_code' => $result->exitCode(),
            ]);

            return [];
        }

        return $this->parse($result->output());
    }

    /**
     * Tab-separated key/value lines into an array.
     *
     * Keeps its argument: a pure text transform with one caller threads no
     * state down anything, and a scratch property to hold the output would be
     * state where there is none.
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
