<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\ScanAborted;
use App\Jobs\ScanConsoleFolder;
use App\Services\LibraryScanner;
use App\Support\Console as ConsoleConfig;
use Illuminate\Console\Command;

class ScanConsole extends Command
{
    protected $signature = 'retrobite:scan
                            {console? : Console key, e.g. psx. Omit to scan every installed console.}
                            {--queue : Queue the scan instead of running it here.}';

    protected $description = 'Read a console folder and record the games and files on it';

    public function handle(LibraryScanner $scanner): int
    {
        $consoles = $this->argument('console') !== null
            ? array_filter([ConsoleConfig::tryFrom((string) $this->argument('console'))])
            : ConsoleConfig::allInstalled()->all();

        if ($consoles === []) {
            $this->error('No such console, or no console has a folder yet.');

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

            $this->info($console->name);
            $this->table(array_keys($result->toArray()), [array_values($result->toArray())]);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
