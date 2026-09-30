<?php

use App\Conversion\Converters;
use App\Conversion\Tools;
use App\Models\ConsoleSourceFolder;
use App\Support\Console;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
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
    #[Url(as: 'console')]
    public string $consoleKey = '';

    /** Where the picker's search starts: text, or comma-separated game ids as a console's shelf sends its picks. */
    #[Url(as: 'search', except: '')]
    public string $search = '';

    public function mount(): void
    {
        if (! $this->consoles->has($this->consoleKey)) {
            $this->consoleKey = (string) $this->consoles->keys()->first();
        }
    }

    /**
     * The installed consoles some converter is offered on, by key.
     *
     * @return Collection<string, Console>
     */
    #[Computed]
    public function consoles(): Collection
    {
        return ConsoleSourceFolder::consoles()
            ->filter(function (Console $console): bool {
                return Converters::offersOn($console);
            })
            ->keyBy(function (Console $console): string {
                return $console->key;
            });
    }

    /** @return list<array{key: string, label: string, configured: string, path: string|null, pinned: string, found: string|null, licence: string, formats: list<string>}> */
    #[Computed]
    public function tools(): array
    {
        return Tools::report();
    }

    public function selectConsole(string $key): void
    {
        if (! $this->consoles->has($key)) {
            return;
        }

        $this->consoleKey = $key;

        // A search is one console's: its ids above all.
        $this->search = '';
    }

    public function refreshTools(): void
    {
        Tools::forget();

        unset($this->tools);
    }
}; ?>

<div class="flex flex-col gap-6">
    {{-- Heading, and the one thing about the whole page rather than one
         conversion: which tools are here. --}}
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="kicker mb-1.5 text-fg-faint">{{ __('Tools') }}</p>
            <h1 class="text-display font-medium tracking-display text-fg-bright">{{ __('Conversion') }}</h1>
        </div>

        <flux:modal.trigger name="conversion-tools">
            <flux:button size="sm" variant="ghost" icon="wrench">{{ __('Tool paths') }}</flux:button>
        </flux:modal.trigger>
    </div>

    @if ($this->consoles->isEmpty())
        <div class="rounded-xl border border-dashed border-line-input px-6 py-10 text-center">
            <p class="text-sm text-fg-soft">{{ __('No console there are conversions for is set up yet.') }}</p>
            <p class="mt-1 text-sm text-fg-faint">{{ __('Add a console whose config lists conversions on the Consoles page to convert its discs.') }}</p>
        </div>
    @else
        <div class="flex flex-wrap items-center gap-1 border-b border-line">
            @foreach ($this->consoles as $key => $console)
                <button
                    type="button"
                    wire:key="tab-{{ $key }}"
                    wire:click="selectConsole(@js($key))"
                    @class([
                        'flex cursor-pointer items-center gap-2 rounded-t-lg border-b-2 px-3 py-2 text-sm transition-colors',
                        'border-accent text-accent' => $key === $consoleKey,
                        'border-transparent text-fg-muted hover:text-fg-soft' => $key !== $consoleKey,
                    ])
                >
                    <img src="{{ $console->icon }}" alt="" class="size-4 object-contain" />
                    {{ $console->name }}
                </button>
            @endforeach
        </div>

        <livewire:conversion.picker :console-key="$consoleKey" :search="$search" wire:key="picker-{{ $consoleKey }}" />
    @endif

    <livewire:conversion.queue />

    <flux:modal name="conversion-tools" class="w-full max-w-4xl">
        <div class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Tool paths') }}</flux:heading>
                <flux:text class="mt-1">
                    {{ __('The command-line tools the conversions run. They ship in the web image, pinned; set CHDMAN_PATH and the like in .env to use others. A missing tool turns off only the formats that need it.') }}
                </flux:text>
            </div>

            <div class="overflow-x-auto rounded-lg border border-line">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-line bg-sunken">
                        <tr>
                            <th class="kicker px-3 py-2 font-normal text-fg-faint">{{ __('Tool') }}</th>
                            <th class="kicker px-3 py-2 font-normal text-fg-faint">{{ __('Path') }}</th>
                            <th class="kicker px-3 py-2 font-normal text-fg-faint">{{ __('Formats') }}</th>
                            <th class="kicker px-3 py-2 font-normal text-fg-faint">{{ __('Pinned / found') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($this->tools as ['key' => $key, 'label' => $label, 'configured' => $configured, 'path' => $path, 'pinned' => $pinned, 'found' => $found, 'licence' => $licence, 'formats' => $formats])
                            <tr wire:key="tool-{{ $key }}">
                                <td class="px-3 py-2">
                                    <span class="text-fg-soft">{{ $label }}</span>
                                    <span class="block text-xs text-fg-faint">{{ $licence }}</span>
                                </td>
                                <td class="px-3 py-2 font-mono text-xs">
                                    @if ($path !== null)
                                        <span class="text-fg-soft">{{ $path }}</span>
                                    @else
                                        <span class="text-danger">{{ __('missing') }}</span>
                                        <span class="block text-fg-faint">{{ $configured }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 font-mono text-xs">
                                    @forelse ($formats as $format)
                                        <span class="block whitespace-nowrap text-fg-soft">{{ $format }}</span>
                                    @empty
                                        <span class="text-fg-faint">{{ __('none yet') }}</span>
                                    @endforelse
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 font-mono text-xs">
                                    <span class="text-fg-muted">{{ $pinned }}</span>
                                    <span class="text-fg-faint">/</span>
                                    <span @class(['text-fg-soft' => $found === $pinned, 'text-warn' => $found !== null && $found !== $pinned, 'text-fg-faint' => $found === null])>{{ $found ?? '—' }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="refreshTools">{{ __('Check again') }}</flux:button>
                <flux:modal.close>
                    <flux:button size="sm" variant="ghost">{{ __('Close') }}</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</div>
