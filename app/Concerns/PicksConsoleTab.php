<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\ConsoleSourceFolder;
use App\Support\Console;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * A Tools page with a tab per console in the library it has something for —
 * Conversion, Decrypt. The tab is in the address, and one the page has
 * nothing for falls back to the first. Drawn with <x-console-tabs>.
 */
trait PicksConsoleTab
{
    #[Url(as: 'console')]
    public string $consoleKey = '';

    /** Whether the page has anything for this console, which gives it a tab. */
    abstract protected function offersConsole(Console $console): bool;

    /** Livewire's mount hook for this trait: land on a tab that exists. */
    public function mountPicksConsoleTab(): void
    {
        if (! $this->consoles->has($this->consoleKey)) {
            $this->consoleKey = (string) $this->consoles->keys()->first();
        }
    }

    /**
     * The installed consoles the page has something for, by key.
     *
     * @return Collection<string, Console>
     */
    #[Computed]
    public function consoles(): Collection
    {
        return ConsoleSourceFolder::consoles()
            ->filter(function (Console $console): bool {
                return $this->offersConsole($console);
            })
            ->keyBy(function (Console $console): string {
                return $console->key;
            });
    }

    /** Switch tab, ignoring a console the page has no tab for. */
    public function selectConsole(string $key): void
    {
        if (! $this->consoles->has($key)) {
            return;
        }

        $this->consoleKey = $key;
        $this->consoleSelected();
    }

    /** What the page lets go of when the tab changes: a search, picks. Nothing by default. */
    protected function consoleSelected(): void {}
}
