<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\ScanAborted;
use App\Jobs\MatchGame;
use App\Jobs\ScanConsoleFolder;
use App\Models\ConsoleSourceFolder;
use App\Services\LibraryScanner;
use App\Support\Console as ConsoleConfig;
use Illuminate\Console\Command;

class ScanConsole extends Command
{
    protected $signature = 'retrobite:scan
                            {console? : Console key, e.g. psx. Omit to scan every console in the library.}
                            {--queue : Queue the scan instead of running it here.}';

    protected $description = 'Read a console folder and record the games and files on it';

    public function handle(LibraryScanner $scanner): int
    {
        $consoles = $this->argument('console') !== null
            ? array_filter([ConsoleConfig::tryFrom((string) $this->argument('console'))])
            : ConsoleSourceFolder::consoles()->all();

        if ($consoles === []) {
            $this->error('No such console, or nothing has been added to the library yet.');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($consoles as $console) {
            if ($this->option('queue')) {
                ScanConsoleFolder::dispatch($console->key);
                $this->line("Queued scan for {$console->name}.");

                continue;
            }

            try {
                $result = $scanner->scan($console);
            } catch (ScanAborted $e) {
                $this->warn("{$console->name}: {$e->getMessage()}");
                $failed = true;

                continue;
            }

            $queued = MatchGame::queueAwaiting($console->key);

            $this->info($console->name);
            $this->table(
                [...array_keys($result->toArray()), 'queued_for_lookup'],
                [[...array_values($result->toArray()), $queued]],
            );
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
