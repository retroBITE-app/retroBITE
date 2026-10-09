<?php

declare(strict_types=1);

namespace App\Conversion;

use App\Enums\ConversionFailure;
use App\Enums\ConversionStatus;
use App\Enums\TransferFailure;
use App\Enums\TransferMode;
use App\Events\SystemUpdated;
use App\Exceptions\ConversionCancelled;
use App\Exceptions\ConversionFailed;
use App\Exceptions\LibraryFileRejected;
use App\Exceptions\LibraryPathException;
use App\Exceptions\TransferFailed;
use App\Jobs\FileTransferJob;
use App\Jobs\ScanConsoleFolder;
use App\Models\Conversion;
use App\Models\ConversionStat;
use App\Models\GameFile;
use App\Services\LibraryFiles;
use App\Support\Console;
use App\Support\LibraryPath;
use App\Support\LiveUpdates;
use App\Support\Scanning\LibraryFolders;
use App\Transfers\FileTransfer;
use App\Transfers\Location;
use Carbon\CarbonInterface;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\FakeInvokedProcess;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Number;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs one queued conversion from start to finish.
 *
 * In order, and all or nothing as far as the library is concerned:
 *
 * 1. the gate is asked again — the console, the source's formats, the tool —
 *    so a row queued before a tool went missing, or forged, never runs;
 * 2. every source is checked on disk, and every output's name checked free;
 * 3. the tool writes into a folder of its own in upload staging, on the same
 *    filesystem as the library, while its output is read for progress and
 *    the row checked for a cancel;
 * 4. the converter confirms what was written, and with verify on the tool's
 *    own verifier checks it;
 * 5. only then does anything reach the console's folder, by FileTransferJob,
 *    which renames rather than copies and never writes over a file — for a
 *    converter that replaces its source, under a hidden name of its own first,
 *    then renamed over the source in one step (LibraryPath::replace());
 * 6. with keep-source off, the sources go — never before the output is in;
 * 7. a set gets a playlist of the new discs, and the console is scanned.
 *
 * Until step 5 any failure leaves nothing behind: the staging folder goes,
 * and the row says why. From step 5 on the output is in the library, so the
 * conversion is done whatever the tidying after it does.
 */
final class ConversionRunner
{
    /** How often a running tool is looked at. */
    private const TICK = 250_000;

    /** How often progress and log go to the row, and the row is asked for a cancel. */
    private const REPORT_EVERY = 1.0;

    /** Seconds into a phase before its pace is worth an ETA. */
    private const ETA_AFTER = 5.0;

    /** The tail of the tool's output that is kept, in characters. */
    private const LOG_LIMIT = 65536;

    /** Seconds kept clear under the long connection's retry_after, so a running job is never handed out twice. */
    private const RETRY_MARGIN = 120;

    /**
     * What a file row knows about the bytes in it, and no longer knows once
     * they are replaced: hashes and the provider's word on the dump.
     */
    private const CONTENT_FACTS = [
        'crc', 'md5', 'sha1', 'hashed_at', 'scrapes', 'provider_flags',
        'ra_hash', 'ra_hash_size', 'ra_hash_mtime', 'ra_hashed_at',
    ];

    /**
     * What the toolbox read off the replaced bytes, for the next scan to read again.
     * Not disc_key: it is the disc's, and kept once decrypting has deleted its file.
     */
    private const META_FACTS = ['license_id', 'video_mode', 'encrypted'];

    private string $log = '';

    private float $lastTick = 0.0;

    private float $progress = 0.0;

    /** When the phase now running — converting, or verifying — began, for its ETA. */
    private float $phaseStarted = 0.0;

    /** When the whole conversion has to be over, every command of every disc together. */
    private float $deadline = 0.0;

    /**
     * The one line that says how the running tool is getting on, rewritten in
     * place at the foot of the log rather than appended each second: the
     * tool's own progress line, or the bytes read for a tool that prints none.
     */
    private string $status = '';

    /** What the tool has printed past its last line break, until the rest of the line comes. */
    private string $buffer = '';

    /** Everything a verify command has printed, for a verifier that says "bad" and exits 0. */
    private string $lastOutput = '';

    private bool $keepOutput = false;

    /** The /proc entry of the file a silent tool is reading, once found: pid and fd. */
    private ?string $readingFd = null;

    /** What went in and what came out, for the size estimates the page learns. */
    private int $sourceBytes = 0;

    private int $outputBytes = 0;

    public function __construct(
        private readonly LibraryPath $paths,
        private readonly LibraryFiles $files,
    ) {}

    /**
     * Seconds one conversion may take: what config says, but never so near
     * the long connection's retry_after that a second worker could pick the
     * job up while it still runs, whatever .env is set to.
     */
    public static function timeout(): int
    {
        $retryAfter = (int) config('queue.connections.database-long.retry_after', 7200);

        return max(1, min((int) config('converters.timeout', 7000), $retryAfter - self::RETRY_MARGIN));
    }

    public function run(Conversion $conversion): void
    {
        $staging = null;

        try {
            [$console, $converter, $set] = $this->admit($conversion);
            $plan = $this->plan($console, $converter, $set);
            $staging = $this->prepareStaging($conversion);

            $this->start($conversion);
            $this->convert($conversion, $converter, $plan, $staging);
            $this->confirm($conversion, $converter, $plan, $staging);
            $this->verify($conversion, $converter, $plan, $staging);
            $this->place($conversion, $console, $converter, $set, $plan, $staging);
        } catch (ConversionCancelled) {
            $this->removeStaging($staging);
            $this->line(__('Cancelled.'));
            $this->finish($conversion, ConversionStatus::Cancelled);

            return;
        } catch (ConversionFailed $e) {
            $this->removeStaging($staging);
            Log::warning('A conversion failed.', ['conversion' => $conversion->id, 'reason' => $e->reason->value, 'detail' => $e->detail]);
            $this->finish($conversion, ConversionStatus::Failed, $e->reason);

            // A source gone from disk means the record is behind the folder —
            // renamed or removed over the share — so scan it and the picker
            // offers what is really there.
            if ($e->reason === ConversionFailure::SourceMissing) {
                ScanConsoleFolder::dispatch($conversion->console);
            }

            return;
        } catch (Throwable $e) {
            $this->removeStaging($staging);
            Log::error('A conversion failed unexpectedly.', ['conversion' => $conversion->id, 'exception' => $e]);
            $this->finish($conversion, ConversionStatus::Failed, ConversionFailure::ToolFailed);

            return;
        }

        // The output is in the library: staging goes before the row says so,
        // so a Retry pressed the moment it does cannot start in a folder this
        // is about to delete.
        $this->removeStaging($staging);
        // A replacing converter swapped its sources out already, in place().
        if (! $converter->replacesSource()) {
            $this->settle($conversion, function () use ($conversion, $set): void {
                $this->removeSources($conversion, $set);
            });
        }
        $this->settle($conversion, function () use ($conversion, $console, $converter, $set, $plan): void {
            $this->writePlaylist($conversion, $console, $converter, $set, $plan);
        });
        $this->finish($conversion, ConversionStatus::Done, null, [
            'source_bytes' => $this->sourceBytes,
            'output_bytes' => $this->outputBytes,
        ]);
        $this->settle($conversion, function () use ($converter, $console): void {
            ConversionStat::record($converter->key(), $console->key, $this->sourceBytes, $this->outputBytes);
            ScanConsoleFolder::dispatch($console->key);
        });
    }

    /**
     * Throw away what an interrupted conversion left: its staging folder and
     * its claim to be running. For the row a worker died holding.
     */
    public function abandon(Conversion $conversion): void
    {
        try {
            $this->removeStaging($this->paths->stagingDirectory().'/'.$conversion->stagingFolder());
        } catch (LibraryPathException) {
            // No library to clean up in.
        }

        $this->log = (string) $conversion->log;

        if ($this->recoverSwap($conversion)) {
            $this->line(__('Finished after a restart: the output had already taken its source\'s place.'));
            $this->finish($conversion, ConversionStatus::Done);
            ScanConsoleFolder::dispatch($conversion->console);

            return;
        }

        $this->line(__('Interrupted.'));
        $this->finish($conversion, ConversionStatus::Failed, ConversionFailure::Interrupted);
    }

    /**
     * The console, the converter and the set, or a refusal.
     *
     * @return array{0: Console, 1: Converter, 2: SourceSet}
     *
     * @throws ConversionFailed
     */
    private function admit(Conversion $conversion): array
    {
        $console = Console::tryFrom($conversion->console);
        $converter = Converters::make($conversion->converter);
        $file = $conversion->game_file_id !== null ? GameFile::query()->with('game')->find($conversion->game_file_id) : null;
        $set = $file !== null ? SourceSet::fromFile($file) : null;

        if ($set === null) {
            throw ConversionFailed::because(ConversionFailure::SourceMissing, 'row '.$conversion->game_file_id);
        }

        if ($console === null || $converter === null || $set->console->key !== $console->key) {
            throw ConversionFailed::because(ConversionFailure::Unsupported, $conversion->converter);
        }

        $offered = Converters::candidatesFor($set)->contains(function (Converter $candidate) use ($converter): bool {
            return $candidate->key() === $converter->key();
        });

        if (! $offered) {
            throw ConversionFailed::because(ConversionFailure::Unsupported, $converter->key());
        }

        $missing = Tools::missing($converter);

        if ($missing !== null) {
            throw ConversionFailed::because(ConversionFailure::ToolMissing, $missing);
        }

        return [$console, $converter, $set];
    }

    /**
     * Each disc and the names it will be written as, once every source has
     * been found and every name found free.
     *
     * @return list<array{disc: Disc, outputs: list<string>}>
     *
     * @throws ConversionFailed
     */
    private function plan(Console $console, Converter $converter, SourceSet $set): array
    {
        foreach ($set->files() as $file) {
            $absolute = LibraryFolders::pathOf($file);

            if (is_link($absolute) || ! is_file($absolute) || ! is_readable($absolute)) {
                throw ConversionFailed::because(ConversionFailure::SourceMissing, $file->path);
            }
        }

        // The discs themselves, measured now: a playlist is a few bytes of
        // names, and the row's size is only as fresh as the last scan.
        $this->sourceBytes = (int) collect($set->discs)
            ->flatMap(function (Disc $disc): array {
                return $disc->members;
            })
            ->sum(function (GameFile $member): int {
                return (int) filesize(LibraryFolders::pathOf($member));
            });

        $plan = [];
        $taken = [];

        foreach ($set->discs as $disc) {
            $outputs = $converter->outputsFor($disc);

            if ($converter->replacesSource() && (count($outputs) !== 1 || count($disc->members) !== 1)) {
                throw ConversionFailed::because(ConversionFailure::Unsupported, $disc->file->path);
            }

            foreach ($outputs as $name) {
                $relative = self::join($set->directory, $name);

                // Its own source's name is free to a converter that replaces
                // it; the name it is parked under on the way in has to be.
                $replacing = $converter->replacesSource() && $this->isSource($console, $disc, $relative);
                $check = $replacing ? self::parked($relative) : $relative;

                // Left by a run that died before its rename: the source never moved,
                // and the queue lets one conversion of a file run at a time.
                if ($replacing && $this->taken($console, $check)) {
                    $this->discard($console, $check);
                }

                if (in_array($relative, $taken, true) || $this->taken($console, $check)) {
                    throw ConversionFailed::because(ConversionFailure::Exists, $check);
                }

                $taken[] = $relative;
            }

            $plan[] = ['disc' => $disc, 'outputs' => $outputs];
        }

        return $plan;
    }

    /**
     * An empty folder of the conversion's own in upload staging. Absolute.
     *
     * @throws ConversionFailed
     */
    private function prepareStaging(Conversion $conversion): string
    {
        try {
            $staging = $this->paths->stagingDirectory().'/'.$conversion->stagingFolder();
        } catch (LibraryPathException $e) {
            throw ConversionFailed::because(ConversionFailure::Unwritable, 'staging', $e);
        }

        $this->removeStaging($staging);
        File::ensureDirectoryExists($staging);

        if (! is_dir($staging) || is_link($staging)) {
            throw ConversionFailed::because(ConversionFailure::Unwritable, $conversion->stagingFolder());
        }

        return $staging;
    }

    private function start(Conversion $conversion): void
    {
        $this->log = '';
        $this->progress = 0.0;
        $this->phaseStarted = microtime(true);
        $this->deadline = $this->phaseStarted + self::timeout();

        $conversion->update([
            'status' => ConversionStatus::Running,
            'progress' => 0,
            'eta_at' => null,
            'failure' => null,
            'log' => null,
            'outputs' => null,
            'started_at' => now(),
            'finished_at' => null,
        ]);

        $this->signal();
    }

    /**
     * Run each disc's commands in turn — one, for most conversions — the bar
     * split evenly between the discs and, within a disc, between its commands.
     *
     * @param  list<array{disc: Disc, outputs: list<string>}>  $plan
     *
     * @throws ConversionFailed
     * @throws ConversionCancelled
     */
    private function convert(Conversion $conversion, Converter $converter, array $plan, string $staging): void
    {
        $count = count($plan);

        foreach ($plan as $index => ['disc' => $disc, 'outputs' => $names]) {
            if ($count > 1) {
                $this->line(__('Disc :number of :count: :name', ['number' => $index + 1, 'count' => $count, 'name' => $disc->file->filename]));
            }

            $input = $converter->input($disc, $this->paths->root(), $staging);
            $outputs = array_map(function (string $name) use ($staging): string {
                return $staging.'/'.$name;
            }, $names);

            $commands = $converter->commands($disc, $input, $outputs, $conversion->options, $this->paths->root(), $staging);
            $steps = count($commands);

            foreach ($commands as $step => ['tool' => $tool, 'arguments' => $arguments, 'reading' => $reading]) {
                $exit = $this->execute($conversion, $converter, $tool, $arguments, $reading, function (float $percent) use ($index, $count, $step, $steps): float {
                    return ($index + ($step + $percent / 100) / $steps) / $count * 100;
                }, directory: $converter->workingDirectory($staging));

                if (! Tools::succeeded($tool, $exit)) {
                    throw ConversionFailed::because(ConversionFailure::ToolFailed, $tool.' exit '.$exit);
                }
            }

            foreach ($outputs as $output) {
                clearstatcache(true, $output);

                if (! is_file($output) || (int) filesize($output) === 0) {
                    throw ConversionFailed::because(ConversionFailure::NoOutput, basename($output));
                }
            }
        }

        $this->report($conversion, 100.0);
    }

    /**
     * Ask the converter about every written file, verify on or off: its own
     * check of what a tool can get wrong and still exit 0.
     *
     * @param  list<array{disc: Disc, outputs: list<string>}>  $plan
     *
     * @throws ConversionFailed
     */
    private function confirm(Conversion $conversion, Converter $converter, array $plan, string $staging): void
    {
        foreach (self::outputs($plan) as $name) {
            $reason = $converter->confirm($staging.'/'.$name);

            if ($reason !== null) {
                $this->line($reason);
                $conversion->update(['log' => $this->log]);

                throw ConversionFailed::because(ConversionFailure::VerifyFailed, $name);
            }
        }
    }

    /**
     * With verify on and a verifier to hand, check every written file.
     *
     * @param  list<array{disc: Disc, outputs: list<string>}>  $plan
     *
     * @throws ConversionFailed
     * @throws ConversionCancelled
     */
    private function verify(Conversion $conversion, Converter $converter, array $plan, string $staging): void
    {
        if (! $conversion->option(Converter::VERIFY) || ! in_array(Converter::VERIFY, $converter->options(), true)) {
            return;
        }

        $outputs = self::outputs($plan)
            ->filter(function (string $name) use ($converter): bool {
                return $converter->verifyArguments($name) !== null;
            })
            ->values();

        if ($outputs->isEmpty()) {
            return;
        }

        $conversion->update(['status' => ConversionStatus::Verifying, 'progress' => 0, 'eta_at' => null]);
        $this->progress = 0.0;
        $this->phaseStarted = microtime(true);
        $this->signal();

        $count = $outputs->count();

        foreach ($outputs as $index => $name) {
            $output = $staging.'/'.$name;

            $exit = $this->execute($conversion, $converter, $converter->tool(), (array) $converter->verifyArguments($output), $output, function (float $percent) use ($index, $count): float {
                return ($index + $percent / 100) / $count * 100;
            }, keepOutput: true, directory: $converter->workingDirectory($staging));

            if (! Tools::succeeded($converter->tool(), $exit) || $converter->verifyFailed($this->lastOutput)) {
                throw ConversionFailed::because(ConversionFailure::VerifyFailed, $name);
            }
        }

        $this->report($conversion, 100.0);
    }

    /**
     * Move every output into the console's folder, all of them or none — into
     * the directory plan() found every name free in.
     *
     * @param  list<array{disc: Disc, outputs: list<string>}>  $plan
     *
     * @throws ConversionFailed
     */
    private function place(Conversion $conversion, Console $console, Converter $converter, SourceSet $set, array $plan, string $staging): void
    {
        $names = self::outputs($plan);

        $this->outputBytes = (int) $names->sum(function (string $name) use ($staging): int {
            return (int) filesize($staging.'/'.$name);
        });

        $moves = array_values($names
            ->map(function (string $name) use ($conversion, $console, $converter, $set): FileTransfer {
                $relative = self::join($set->directory, $name);

                return new FileTransfer(
                    Location::staging($conversion->stagingFolder().'/'.$name),
                    Location::library($console, $converter->replacesSource() ? self::parked($relative) : $relative),
                );
            })
            ->all());

        try {
            FileTransferJob::now($moves, TransferMode::Move);
        } catch (TransferFailed $e) {
            throw ConversionFailed::because(
                $e->reason === TransferFailure::Exists ? ConversionFailure::Exists : ConversionFailure::Unwritable,
                $e->path,
                $e,
            );
        }

        if ($converter->replacesSource()) {
            $this->swap($console, $converter, $set, $plan);
        }

        $conversion->update(['outputs' => $names->all(), 'directory' => $set->directory]);

        foreach ($names as $name) {
            $this->line(__('Wrote :path', ['path' => self::join($set->directory, $name)]));
        }
    }

    /**
     * Swap each parked output in for its source, keeping the source's row and
     * dropping its companions. A source that will not go leaves everything as it was.
     *
     * @param  list<array{disc: Disc, outputs: list<string>}>  $plan
     *
     * @throws ConversionFailed
     */
    private function swap(Console $console, Converter $converter, SourceSet $set, array $plan): void
    {
        foreach ($plan as ['disc' => $disc, 'outputs' => $names]) {
            $final = self::join($set->directory, (string) Arr::first($names));
            $parked = self::parked($final);

            // One rename over the source: there is never a moment without the game's file.
            try {
                $this->paths->replace($console, $parked, $final);
            } catch (LibraryPathException $e) {
                $this->discard($console, $parked);

                throw ConversionFailed::because(ConversionFailure::Unwritable, $final, $e);
            }

            $this->finishSwap($console, $converter, $disc, $final);
        }
    }

    /**
     * After a swap's rename: the row's size and stale facts brought up to date,
     * the source's companions gone. Safe to run twice, as recovery may.
     */
    private function finishSwap(Console $console, Converter $converter, Disc $disc, string $final): void
    {
        $disc->file->update([
            'size_bytes' => (int) filesize($this->paths->absolute($console, $final)),
            ...array_fill_keys(self::CONTENT_FACTS, null),
        ]);
        $disc->file->meta()->update(array_fill_keys(self::META_FACTS, null));
        $this->line(__('Replaced :path', ['path' => $final]));

        foreach ($converter->companions($disc, $this->paths->root()) as $companion) {
            $relative = $this->paths->consoleRelative($console, $companion);

            if ($relative === null || ! $this->paths->exists($console, $relative)) {
                continue;
            }

            try {
                $this->paths->delete($console, $relative);
                $this->line(__('Deleted :path', ['path' => $relative]));
            } catch (LibraryPathException) {
                $this->line(__('Kept :path: it could not be deleted.', ['path' => $relative]));
            }
        }
    }

    /**
     * A replacing conversion the worker died in: a parked output is removed (the
     * source never moved), a landed rename is finished. Whether it is now done.
     */
    private function recoverSwap(Conversion $conversion): bool
    {
        $console = Console::tryFrom($conversion->console);
        $converter = Converters::make($conversion->converter);
        $file = $conversion->game_file_id !== null ? GameFile::query()->with('game')->find($conversion->game_file_id) : null;
        $set = $file !== null ? SourceSet::fromFile($file) : null;

        if ($console === null || $converter === null || $set === null || ! $converter->replacesSource()) {
            return false;
        }

        $landed = true;

        foreach ($set->discs as $disc) {
            $final = self::join($set->directory, (string) Arr::first($converter->outputsFor($disc)));
            $parked = self::parked($final);

            try {
                if ($this->paths->exists($console, $parked)) {
                    $this->discard($console, $parked);
                    $this->line(__('Removed the unfinished :path; :source is as it was.', ['path' => $parked, 'source' => $final]));
                    $landed = false;

                    continue;
                }

                if ($converter->confirm($this->paths->absolute($console, $final)) !== null) {
                    $landed = false;

                    continue;
                }
            } catch (LibraryPathException) {
                $landed = false;

                continue;
            }

            $this->finishSwap($console, $converter, $disc, $final);
        }

        return $landed;
    }

    /** Remove a parked output that will not be swapped in after all. */
    private function discard(Console $console, string $parked): void
    {
        try {
            $this->paths->delete($console, $parked);
        } catch (LibraryPathException) {
            $this->line(__('Left :path behind: it could not be removed.', ['path' => $parked]));
        }
    }

    /** Whether a path in the console's folder is this disc's own file. */
    private function isSource(Console $console, Disc $disc, string $relative): bool
    {
        return $this->paths->consoleRelative($console, $disc->file->path) === $relative;
    }

    /**
     * With keep-source off, delete what was converted, now the output is in.
     * A source that will not go is logged and left: two copies is a nuisance,
     * and the conversion itself succeeded.
     */
    private function removeSources(Conversion $conversion, SourceSet $set): void
    {
        if ($conversion->option(Converter::KEEP_SOURCE) || $set->game === null) {
            return;
        }

        foreach ($set->files() as $file) {
            try {
                $this->files->deleteFile($set->game, $file->id);
                $this->line(__('Deleted :path', ['path' => $file->filename]));
            } catch (LibraryFileRejected $e) {
                Log::warning('A converted source could not be deleted.', ['conversion' => $conversion->id, 'file' => $file->id, 'reason' => $e->reason->value]);
                $this->line(__('Kept :path: it could not be deleted.', ['path' => $file->filename]));
            }
        }
    }

    /**
     * A playlist of the new discs, for a set: named as the old one was, or
     * with the format beside it where the old one is still there.
     *
     * @param  list<array{disc: Disc, outputs: list<string>}>  $plan
     *
     * @throws LibraryPathException
     */
    private function writePlaylist(Conversion $conversion, Console $console, Converter $converter, SourceSet $set, array $plan): void
    {
        if (! $set->isSet()) {
            return;
        }

        $entries = collect($plan)
            ->map(function (array $step) use ($converter): string {
                return $converter->playlistEntry($step['outputs']);
            })
            ->implode("\n");

        $name = collect([$set->stem().'.m3u', $set->stem().' ('.Str::upper($converter->to()).').m3u'])
            ->first(function (string $candidate) use ($console, $set): bool {
                return ! $this->taken($console, self::join($set->directory, $candidate));
            });

        if ($name === null) {
            $this->line(__('No playlist was written: the names it would take are in use.'));

            return;
        }

        $relative = self::join($set->directory, $name);

        $this->paths->put($console, $relative, $entries."\n");
        $conversion->update(['outputs' => [...($conversion->outputs ?? []), $name]]);
        $this->line(__('Wrote :path', ['path' => $relative]));
    }

    /**
     * One step of tidying after the output is placed. Whatever it throws is
     * logged and noted, not fatal: the conversion itself is done.
     */
    private function settle(Conversion $conversion, callable $step): void
    {
        try {
            $step();
        } catch (Throwable $e) {
            Log::warning('A step after a finished conversion failed.', ['conversion' => $conversion->id, 'exception' => $e]);
            $this->line(__('A step after the conversion failed; the output is in place. See the application log.'));

            // Saved here too: the last of these steps runs after finish().
            $conversion->update(['log' => $this->log]);
        }
    }

    /**
     * Run one command to the end, reading its output as it goes. Returns the
     * exit code. Bounded by what is left of the conversion's time, not a full
     * timeout of its own.
     *
     * @param  list<string>  $arguments
     * @param  callable(float): float  $overall  one run's percent to the whole conversion's
     *
     * @throws ConversionFailed
     * @throws ConversionCancelled
     */
    private function execute(Conversion $conversion, Converter $converter, string $tool, array $arguments, string $reading, callable $overall, bool $keepOutput = false, ?string $directory = null): int
    {
        $binary = Tools::path($tool);
        $remaining = (int) ceil($this->deadline - microtime(true));

        if ($binary === null) {
            throw ConversionFailed::because(ConversionFailure::ToolMissing, $tool);
        }

        if ($remaining <= 0) {
            throw ConversionFailed::because(ConversionFailure::TimedOut, $tool);
        }

        $this->line('$ '.collect([basename($binary), ...$arguments])
            ->map(function (string $part): string {
                return Str::contains($part, ' ') ? '"'.$part.'"' : $part;
            })
            ->implode(' '));

        $this->buffer = '';
        $this->lastOutput = '';
        $this->keepOutput = $keepOutput;
        $this->readingFd = null;

        $pending = Process::timeout($remaining);
        $process = ($directory !== null ? $pending->path($directory) : $pending)->start([$binary, ...$arguments]);
        $target = realpath($reading);
        $size = $target !== false ? (int) filesize($target) : 0;
        $parsed = false;

        try {
            while ($process->running()) {
                $parsed = $this->consume($converter, $process, $overall) || $parsed;

                if (! $parsed && $size > 0) {
                    $this->estimate($process, $target, $size, $overall);
                }

                $this->tick($conversion, $process);
                $process->ensureNotTimedOut();

                Sleep::usleep(self::TICK);
            }
        } catch (ProcessTimedOutException $e) {
            $process->stop(5);

            throw ConversionFailed::because(ConversionFailure::TimedOut, $tool, $e);
        }

        $this->consume($converter, $process, $overall, flush: true);

        // Over: the running line has said all it had to, and the next thing
        // in the log is what came of it.
        $this->status = '';

        return (int) $process->wait()->exitCode();
    }

    /**
     * Read what the tool has printed since last time: progress lines move the
     * bar, everything else goes to the log. Only whole lines — a read can end
     * mid-line, and half of "45.2% complete" is not 5.2% — unless the tool is
     * done. Returns whether any progress line was seen.
     *
     * @param  callable(float): float  $overall
     */
    private function consume(Converter $converter, InvokedProcess|FakeInvokedProcess $process, callable $overall, bool $flush = false): bool
    {
        $chunk = $process->latestErrorOutput().$process->latestOutput();

        if ($this->keepOutput) {
            $this->lastOutput .= $chunk;
        }

        $this->buffer .= $chunk;
        $whole = $flush ? $this->buffer : Str::match('/^(.*[\r\n])/s', $this->buffer);
        $this->buffer = Str::substr($this->buffer, Str::length($whole));
        $parsed = false;

        foreach (Str::of($whole)->split('/[\r\n]+/') as $line) {
            $line = trim((string) $line);

            if ($line === '') {
                continue;
            }

            $percent = $converter->progress($line);

            if ($percent !== null) {
                $this->progress = $overall($percent);
                $this->status = $this->relative($line);
                $parsed = true;

                continue;
            }

            $this->line($line);
        }

        return $parsed;
    }

    /**
     * Progress for a tool that prints none, from how far into its file it has
     * read.
     *
     * @param  callable(float): float  $overall
     */
    private function estimate(InvokedProcess|FakeInvokedProcess $process, string $target, int $size, callable $overall): void
    {
        $offset = $this->readOffset($process, $target);

        if ($offset === null) {
            return;
        }

        $percent = min(99.0, 100 * $offset / $size);
        $this->progress = $overall($percent);
        $this->status = __('Read :done of :total (:percent%)', [
            'done' => Number::fileSize(min($offset, $size), 1),
            'total' => Number::fileSize($size, 1),
            'percent' => (int) $percent,
        ]);
    }

    /**
     * How far into a file a process has read, from /proc: for the tools that
     * print no progress when their output is not a terminal. Null where there
     * is no /proc to ask.
     *
     * Two readings, the larger kept. The file's own offset is exact for a tool
     * that reads it front to back with read(). maxcso reads through libuv's
     * pread(), which never moves the offset, so its stays at 0; for that the
     * process's total bytes read (rchar) stands in, a few libraries' worth
     * over the file's share. The file's descriptor is looked for once, not on
     * every tick.
     */
    private function readOffset(InvokedProcess|FakeInvokedProcess $process, string $target): ?int
    {
        $pid = $process->id();

        if ($pid === null || ! is_dir('/proc/'.$pid.'/fd')) {
            return null;
        }

        $this->readingFd ??= collect(@scandir('/proc/'.$pid.'/fd') ?: [])
            ->first(function (string $fd) use ($pid, $target): bool {
                return ctype_digit($fd) && @readlink('/proc/'.$pid.'/fd/'.$fd) === $target;
            });

        $position = $this->readingFd !== null
            ? (int) Str::match('/^pos:\s+(\d+)/m', (string) @file_get_contents('/proc/'.$pid.'/fdinfo/'.$this->readingFd))
            : 0;
        $read = (int) Str::match('/^rchar:\s+(\d+)/m', (string) @file_get_contents('/proc/'.$pid.'/io'));

        return max($position, $read) > 0 ? max($position, $read) : null;
    }

    /**
     * Once a second: progress and log to the row, and the row asked whether
     * somebody pressed Cancel — stopping the tool if so, SIGTERM first and
     * SIGKILL after five seconds.
     *
     * @throws ConversionCancelled
     */
    private function tick(Conversion $conversion, InvokedProcess|FakeInvokedProcess $process): void
    {
        $time = microtime(true);

        if ($this->lastTick !== 0.0 && $time - $this->lastTick < self::REPORT_EVERY) {
            return;
        }

        $this->lastTick = $time;
        $this->report($conversion, $this->progress);

        if (Conversion::query()->whereKey($conversion->id)->value('cancel_requested_at') === null) {
            return;
        }

        $process->stop(5);

        throw new ConversionCancelled;
    }

    /** Write progress and log to the row, then say so. */
    private function report(Conversion $conversion, float $progress): void
    {
        $conversion->update([
            'progress' => round($progress, 2),
            'eta_at' => $this->eta($progress, microtime(true)),
            'log' => $this->status !== '' ? $this->log.$this->status."\n" : $this->log,
        ]);

        $this->signal();
    }

    /**
     * When the running phase should end, at the pace it has kept so far. None
     * until there is a pace to go by — a few seconds in and past the first
     * percent — nor once it is all but done.
     */
    private function eta(float $progress, float $time): ?CarbonInterface
    {
        $elapsed = $time - $this->phaseStarted;

        if ($this->phaseStarted === 0.0 || $elapsed < self::ETA_AFTER || $progress < 1.0 || $progress >= 100.0) {
            return null;
        }

        return now()->addSeconds((int) round($elapsed * (100 - $progress) / $progress));
    }

    /** @param  array<string, mixed>  $extra */
    private function finish(Conversion $conversion, ConversionStatus $status, ?ConversionFailure $failure = null, array $extra = []): void
    {
        $conversion->update([
            ...$extra,
            'status' => $status,
            'eta_at' => null,
            'failure' => $failure,
            'log' => $this->log !== '' ? $this->log : $conversion->log,
            'finished_at' => now(),
            ...($status === ConversionStatus::Done ? ['progress' => 100] : []),
        ]);

        $this->signal();
    }

    /**
     * One line into the log, with the library's absolute paths made relative.
     * The tail is cut by characters, not bytes, so a tool's "❌" or a
     * non-ASCII file name is never split into something the database refuses.
     */
    private function line(string $line): void
    {
        $this->log .= $this->relative($line)."\n";

        if (Str::length($this->log) > self::LOG_LIMIT) {
            $this->log = '…'.Str::substr($this->log, -self::LOG_LIMIT);
        }
    }

    /** Container paths out of anything a person reads. */
    private function relative(string $text): string
    {
        // Some tools print a path without its leading slash, nodtool among them.
        $root = $this->paths->root();
        $bare = ltrim($root, '/');

        return str_replace(
            [$root.'/'.LibraryPath::STAGING.'/', $root.'/', $bare.'/'.LibraryPath::STAGING.'/', $bare.'/'],
            '',
            $text,
        );
    }

    /**
     * Whether something is at a path inside the console's folder, counting a refusal as taken.
     *
     * @throws ConversionFailed
     */
    private function taken(Console $console, string $relative): bool
    {
        try {
            return $this->paths->exists($console, $relative) || is_link($this->paths->absolute($console, $relative));
        } catch (LibraryPathException $e) {
            throw ConversionFailed::because(ConversionFailure::Unwritable, $relative, $e);
        }
    }

    private function removeStaging(?string $staging): void
    {
        if ($staging === null || is_link($staging) || ! is_dir($staging)) {
            return;
        }

        File::deleteDirectory($staging);
    }

    private function signal(): void
    {
        LiveUpdates::system(SystemUpdated::CONVERSION);
    }

    /**
     * Every file name the plan writes, in order.
     *
     * @param  list<array{disc: Disc, outputs: list<string>}>  $plan
     * @return Collection<int, string>
     */
    private static function outputs(array $plan): Collection
    {
        /** @var Collection<int, string> */
        return collect($plan)->pluck('outputs')->flatten()->values();
    }

    private static function join(string $directory, string $name): string
    {
        return $directory === '' ? $name : $directory.'/'.$name;
    }

    /**
     * Where a replacing output waits beside its source until the source has
     * gone. Hidden, as staging is, and not an extension the scanner plays.
     */
    private static function parked(string $relative): string
    {
        $directory = dirname($relative);

        return self::join($directory === '.' ? '' : $directory, '.'.basename($relative).'.retrobite-replacing');
    }
}
