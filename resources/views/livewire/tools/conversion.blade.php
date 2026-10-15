<?php

use App\Concerns\PicksConsoleTab;
use App\Conversion\Converters;
use App\Support\Console;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Tools → Conversion: a console's discs, what they can become, and the queue.
 *
 * The page is the console tabs and the Tool paths panel. Picking and queueing
 * is conversion.picker, the same component a game's Conversion tab uses,
 * keyed to the tab so switching console starts from nothing; the queue is
 * conversion.queue, every conversion on every console.
 */
new #[Title('Conversion')] class extends Component
{
    use PicksConsoleTab;

    /** Where the picker's search starts: text, or comma-separated game ids as a console's shelf sends its picks. */
    #[Url(as: 'search', except: '')]
    public string $search = '';

    /** A tab for every console some converter in the picker is offered on. */
    protected function offersConsole(Console $console): bool
    {
        return Converters::offersOn($console);
    }

    /** A search is one console's: its ids above all. */
    protected function consoleSelected(): void
    {
        $this->search = '';
    }
}; ?>

<div class="flex flex-col gap-6">
    {{-- Heading, and the one thing about the whole page rather than one
         conversion: which tools are here. --}}
    <x-page-header :kicker="__('Tools')" :title="__('Conversion')" class="mb-0!">
        <x-slot:actions>
            <livewire:conversion.tool-paths :only="App\Conversion\Tools::onPage(null)" />
        </x-slot:actions>
    </x-page-header>

    @if ($this->consoles->isEmpty())
        <x-empty-state
            :title="__('No console there are conversions for is set up yet.')"
            :hint="__('Add a console whose config lists conversions on the Consoles page to convert its discs.')"
        />
    @else
        <x-console-tabs :consoles="$this->consoles" :selected="$consoleKey" />

        <livewire:conversion.picker :console-key="$consoleKey" :search="$search" wire:key="picker-{{ $consoleKey }}" />
    @endif

    <livewire:conversion.queue />
</div>
