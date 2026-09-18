<?php

use App\Resources\ConsoleResource;
use App\Support\ConsoleOverrides;
use Flux\Flux;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Console settings')] class extends Component
{
    public const MODAL = 'edit-console';

    /** 135 consoles is a long way to scroll to reach one. */
    public string $search = '';

    /** The console open in the modal, '' when none is. */
    public string $editing = '';

    /** @var array<string, string> raw form values, keyed by config key */
    public array $fields = [];

    /**
     * Every console in display order, with whether it has been edited.
     *
     * ConsoleResource rather than Console: this list is about the config entry
     * rather than the library, and it is the reader that honours `order`.
     *
     * @return Collection<int, array{key: string, name: string, brand: string, icon: string, overridden: bool}>
     */
    #[Computed]
    public function consoles(): Collection
    {
        $needle = trim(mb_strtolower($this->search));

        return ConsoleResource::all()
            ->filter(function (ConsoleResource $console) use ($needle): bool {
                if ($needle === '') {
                    return true;
                }

                // Brand as well as name, so "sega" finds the Mega Drive.
                return str_contains(mb_strtolower($console->name.' '.$console->brand.' '.$console->key), $needle);
            })
            ->map(fn (ConsoleResource $console): array => [
                'key' => $console->key,
                'name' => (string) $console->name,
                'brand' => (string) $console->brand,
                'icon' => (string) $console->icon,
                'overridden' => ConsoleOverrides::has($console->key),
            ])
            ->values();
    }

    /**
     * The fields the modal draws, already carrying everything the markup needs
     * so it can destructure rather than subscript an optional key.
     *
     * @return array<int, array{name: string, label: string, description: string, type: string, placeholder: string, spans: bool}>
     */
    #[Computed]
    public function formFields(): array
    {
        $placeholders = $this->editing !== '' ? ConsoleOverrides::toPlaceholders($this->editing) : [];

        return Collection::make(ConsoleOverrides::schema())
            ->map(function (array $field, string $name) use ($placeholders): array {
                $type = (string) Arr::get($field, 'type', 'text');

                return [
                    'name' => $name,
                    'label' => (string) Arr::get($field, 'label', $name),
                    'description' => (string) Arr::get($field, 'description', ''),
                    'type' => $type,
                    'placeholder' => (string) Arr::get($placeholders, $name, ''),
                    // A list of extensions runs long enough that half a row cuts it off.
                    'spans' => str_ends_with($type, '[]'),
                ];
            })
            ->values()
            ->all();
    }

    /** How many consoles differ from their config file. Gates the reset control. */
    #[Computed]
    public function editedCount(): int
    {
        return count(ConsoleOverrides::all());
    }

    /** The console the modal is about, for its heading. */
    #[Computed]
    public function editingConsole(): ?ConsoleResource
    {
        return $this->editing !== '' ? ConsoleResource::make($this->editing) : null;
    }

    /** Does the open console differ from its config file? Gates the reset button. */
    #[Computed]
    public function editingOverridden(): bool
    {
        return $this->editing !== '' && ConsoleOverrides::has($this->editing);
    }

    public function edit(string $key): void
    {
        if (! ConsoleResource::exists($key)) {
            return;
        }

        $this->resetValidation();

        $this->editing = $key;
        // Seeded from what is in force rather than from the override, so the form
        // shows the console as it actually behaves.
        $this->fields = ConsoleOverrides::toForm($key);

        Flux::modal(self::MODAL)->show();
    }

    public function closeEdit(): void
    {
        $this->reset('editing', 'fields');
        $this->resetValidation();

        Flux::modal(self::MODAL)->close();
    }

    public function save(): void
    {
        $this->validate(ConsoleOverrides::rules(), attributes: ConsoleOverrides::attributes());

        ConsoleOverrides::remember($this->editing, $this->fields);

        // Read back after the merge, so a renamed console announces its new name.
        $name = (string) (ConsoleResource::make($this->editing)?->name ?? $this->editing);

        $this->closeEdit();
        unset($this->consoles, $this->editedCount);

        Flux::toast(variant: 'success', text: __(':console saved.', ['console' => $name]));
    }

    /** Put every console back, dropping the stored overrides wholesale. */
    public function restoreAll(): void
    {
        $count = count(ConsoleOverrides::all());

        if ($count === 0) {
            return;
        }

        ConsoleOverrides::forgetAll();

        unset($this->consoles, $this->editedCount);

        Flux::toast(variant: 'success', text: trans_choice(
            '{1} One console put back to its shipped settings.|[2,*] :count consoles put back to their shipped settings.',
            $count,
            ['count' => $count],
        ));
    }

    /** Put one console back to what its config file says. */
    public function restore(): void
    {
        if ($this->editing === '') {
            return;
        }

        ConsoleOverrides::forget($this->editing);

        $name = (string) (ConsoleResource::make($this->editing)?->name ?? $this->editing);

        $this->closeEdit();
        unset($this->consoles, $this->editedCount);

        Flux::toast(variant: 'success', text: __(':console put back to its shipped settings.', ['console' => $name]));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout wide :heading="__('Consoles')" :subheading="__('Change what retroBite ships for a console')">
        <x-slot name="actions">
            <div class="flex items-center gap-2">
                @if ($this->editedCount > 0)
                    {{-- Only once there is something to undo: a reset that resets
                         nothing is a button that only ever worries people. --}}
                    <flux:button size="sm" variant="subtle" icon="arrow-uturn-left" wire:click="restoreAll"
                                 wire:confirm="{{ trans_choice('{1} Put one edited console back to its shipped settings? This cannot be undone.|[2,*] Put all :count edited consoles back to their shipped settings? This cannot be undone.', $this->editedCount, ['count' => $this->editedCount]) }}">
                        {{ __('Reset all') }}
                    </flux:button>
                @endif

                <flux:input wire:model.live.debounce.200ms="search" icon="magnifying-glass"
                            :placeholder="__('Search by name or brand')" size="sm" class="w-64" />
            </div>
        </x-slot>

        @if ($this->consoles->isEmpty())
            <div class="rounded-xl border border-dashed border-line-input px-6 py-12 text-center">
                <p class="text-sm text-fg-soft">{{ __('No console matches that.') }}</p>
            </div>
        @else
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($this->consoles as ['key' => $key, 'name' => $name, 'brand' => $brand, 'icon' => $icon, 'overridden' => $overridden])
                    <button
                        type="button"
                        wire:key="console-{{ $key }}"
                        wire:click="edit('{{ $key }}')"
                        @class([
                            'flex cursor-pointer items-center gap-4 rounded-xl border bg-surface p-4 text-left transition-colors hover:border-line-input hover:bg-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep',
                            'border-accent-tint/55' => $overridden,
                            'border-line' => ! $overridden,
                        ])
                    >
                        <img src="{{ $icon }}" alt="" class="size-12 shrink-0 object-contain" />

                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-fg-bright">{{ $name }}</p>
                            <p class="mt-0.5 truncate text-sm text-fg-faint">{{ $brand }}</p>
                            <p class="mt-0.5 truncate font-mono text-xs text-fg-faint">{{ $key }}</p>
                        </div>

                        @if ($overridden)
                            <span class="shrink-0 rounded-full border border-accent-tint/55 bg-accent-tint/10 px-2 py-0.5 text-xs text-accent-light">
                                {{ __('Edited') }}
                            </span>
                        @endif
                    </button>
                @endforeach
            </div>
        @endif

        <flux:modal :name="$this::MODAL" wire:close="closeEdit" class="w-full max-w-2xl">
            @if ($this->editingConsole !== null)
                <form wire:submit="save" class="flex flex-col gap-5">
                    <div>
                        <flux:heading size="lg">{{ $this->editingConsole->name }}</flux:heading>
                        <flux:text class="mt-2">
                            {{ __('An empty field falls back to what the console file ships, shown as its placeholder.') }}
                        </flux:text>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2" data-field-grid>
                        {{-- A cell of its own per field, rather than a class on the
                             input: flux:input forwards class to the control, and
                             app.css makes every field a grid, so the span landed
                             inside the field and pushed its description up beside
                             the label. data-field-grid carries the row template
                             that keeps the inputs level across a row. --}}
                        @foreach ($this->formFields as ['name' => $field, 'label' => $label, 'description' => $description, 'type' => $type, 'placeholder' => $placeholder, 'spans' => $spans])
                            <div wire:key="field-{{ $this->editing }}-{{ $field }}"
                                 @class(['sm:col-span-2' => $spans])>
                                <flux:input
                                    wire:model="fields.{{ $field }}"
                                    :label="__($label)"
                                    :description="filled($description) ? __($description) : null"
                                    :placeholder="$placeholder"
                                    :type="$type === 'number' ? 'number' : 'text'"
                                />
                            </div>
                        @endforeach
                    </div>

                    <div class="flex items-center justify-between gap-3">
                        <div>
                            @if ($this->editingOverridden)
                                <flux:button type="button" size="sm" variant="subtle" wire:click="restore"
                                             wire:confirm="{{ __('Put :console back to its shipped settings?', ['console' => $this->editingConsole->name]) }}">
                                    {{ __('Reset to default') }}
                                </flux:button>
                            @endif
                        </div>

                        <div class="flex items-center gap-2">
                            <flux:button type="button" variant="ghost" wire:click="closeEdit">{{ __('Cancel') }}</flux:button>
                            <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                        </div>
                    </div>
                </form>
            @endif
        </flux:modal>
    </x-settings.layout>
</section>
