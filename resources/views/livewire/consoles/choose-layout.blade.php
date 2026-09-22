<?php

use App\Models\ConsoleSourceFolder;
use App\Support\Console;
use App\Support\Layouts\ConsoleLayout;
use App\Support\Layouts\Layouts;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /** The console being added or edited. */
    public string $console = '';

    public string $layout = '';

    /** Changes the wording: adding a console is a step, editing one is not. */
    public bool $editing = false;

    public function mount(string $console, ?string $layout = null, bool $editing = false): void
    {
        $this->console = $console;
        $this->editing = $editing;

        $consoleObject = Console::tryFrom($console);

        // Whatever the console is read as today, which is the stored layout for
        // one already in the library and its declared default for one being
        // added. Opening this to change a layout should start from the one in
        // force rather than make somebody find it again.
        $this->layout = $layout
            ?? ($consoleObject !== null ? ConsoleSourceFolder::layoutKeyFor($consoleObject) : Layouts::FALLBACK);
    }

    /**
     * The layout in force, for the badge — or null for a console being added.
     *
     * Absent rather than defaulted: a console that is not in the library yet
     * has no current arrangement, and marking its default as "current" would
     * claim something about a drive nobody has looked at.
     */
    #[Computed]
    public function current(): ?string
    {
        $console = Console::tryFrom($this->console);

        if ($console === null || ! ConsoleSourceFolder::has($console)) {
            return null;
        }

        return ConsoleSourceFolder::layoutKeyFor($console);
    }

    /**
     * The arrangements this console offers.
     *
     * @return array<int, array{value: string, label: string, description: string}>
     */
    #[Computed]
    public function layouts(): array
    {
        $console = Console::tryFrom($this->console);

        if ($console === null) {
            return [];
        }

        return Layouts::supportedBy($console)
            ->map(function (ConsoleLayout $layout): array {
                return [
                    'value' => $layout->key(),
                    'label' => $layout->label(),
                    'description' => $layout->description(),
                ];
            })
            ->all();
    }

    /**
     * Hand the choice back to whoever opened this.
     *
     * Nothing is written here: the console is added in one go at the end, so a
     * wizard somebody walks away from leaves no half-added console behind.
     */
    public function choose(): void
    {
        $console = Console::tryFrom($this->console);

        if ($console === null) {
            return;
        }

        $this->validate([
            'layout' => ['required', 'string', Rule::in(Layouts::keysFor($console))],
        ]);

        $this->dispatch('layout-chosen', console: $this->console, layout: $this->layout);
    }
}; ?>

<div class="flex flex-col gap-5">
    @php($console = App\Support\Console::tryFrom($console))

    <div>
        <flux:heading size="lg">{{ __('How is the folder arranged?') }}</flux:heading>
        <flux:text class="mt-2">
            {{ __('This is how :console\'s files sit on disk. It changes what is read as a game and what is skipped.', [
                'console' => $console?->name ?? $this->console,
            ]) }}
        </flux:text>

        {{-- Said here because choosing a layout writes to somebody's drive
             without asking again. The second sentence is the one that matters:
             "creates folders" is a fair thing to read as "moves my ISOs into
             them", and nothing here moves anything. --}}
        <flux:text class="mt-1.5 text-fg-faint">
            {{ __('Any folders the layout needs are created if they are not already there. Nothing existing is moved or renamed.') }}
        </flux:text>
    </div>

    <div class="grid gap-1.5">
        @foreach ($this->layouts as ['value' => $value, 'label' => $label, 'description' => $description])
            <button
                type="button"
                wire:key="layout-{{ $value }}"
                wire:click="$set('layout', @js($value))"
                @class([
                    'cursor-pointer rounded-lg border px-3.5 py-2.5 text-left transition-colors',
                    'border-accent-tint/55 bg-accent-tint/10' => $layout === $value,
                    'border-line-input hover:bg-hover' => $layout !== $value,
                ])
            >
                <span class="flex items-center gap-2">
                    <span @class([
                        'text-sm',
                        'text-accent' => $layout === $value,
                        'text-fg-soft' => $layout !== $value,
                    ])>{{ $label }}</span>

                    {{-- Which one the console is read as today, so changing it
                         says what is being changed from. Absent while adding a
                         console: nothing is in force yet. --}}
                    @if ($this->current === $value)
                        <span class="rounded-md border border-line-strong bg-surface px-1.5 py-0.5 font-mono text-[10px] tracking-kicker text-fg-muted uppercase">
                            {{ __('Current') }}
                        </span>
                    @endif
                </span>
                <span class="mt-0.5 block text-xs text-fg-faint">{{ $description }}</span>
            </button>
        @endforeach
    </div>

    <flux:error name="layout" />

    <div class="flex justify-end gap-2">
        <flux:button size="sm" variant="ghost" wire:click="$dispatch('layout-cancelled')">
            {{ __('Back') }}
        </flux:button>

        <flux:button size="sm" variant="primary" wire:click="choose" :disabled="$this->layouts === []">
            {{ $editing ? __('Save layout') : __('Add console') }}
        </flux:button>
    </div>
</div>
