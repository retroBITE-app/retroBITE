<?php

use Flux\Flux;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Reactive;
use Livewire\Component;

new class extends Component
{
    public const MODAL = 'add-reference';

    /** The heading references are collected under, at the foot of the document. */
    public const HEADING = '## References';

    public string $path = '';

    /**
     * The body being edited, not the one on disk. Reactive so that appending a
     * reference cannot quietly discard whatever else the draft has gained.
     */
    #[Reactive]
    public string $body = '';

    public string $title = '';

    public string $url = '';

    public string $note = '';

    /**
     * References already listed, so the dialog shows what it appends to.
     *
     * @return Collection<int, string>
     */
    #[Computed]
    public function existing(): Collection
    {
        return Collection::make(explode("\n", (string) Str::after($this->body, self::HEADING)))
            ->map(fn (string $line): string => trim($line))
            ->takeWhile(fn (string $line): bool => ! Str::startsWith($line, '## '))
            ->filter(fn (string $line): bool => preg_match('/^\d+\.\s/', $line) === 1)
            ->map(fn (string $line): string => trim((string) preg_replace('/^\d+\.\s*/', '', $line)))
            ->values();
    }

    public function append(): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:200'],
            'url' => ['required', 'url:http,https'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $note = (string) Arr::get($validated, 'note');

        $entry = ($this->existing()->count() + 1).'. ['.Arr::get($validated, 'title').']('.Arr::get($validated, 'url').')'
            .($note === '' ? '' : ' — '.$note);

        $this->dispatch('doc-body-replaced', body: $this->withEntry($entry));
        $this->reset('title', 'url', 'note');

        Flux::modal(self::MODAL)->close();
    }

    /**
     * Append one numbered entry under the heading, creating the heading when a
     * hand-written document has none.
     */
    private function withEntry(string $entry): string
    {
        return Str::contains($this->body, self::HEADING)
            ? rtrim($this->body)."\n".$entry."\n"
            : rtrim($this->body)."\n\n".self::HEADING."\n\n".$entry."\n";
    }
};
?>

<div>
    <flux:modal.trigger :name="$this::MODAL">
        <flux:button size="xs" variant="ghost" icon="link">{{ __('Reference') }}</flux:button>
    </flux:modal.trigger>

    <flux:modal :name="$this::MODAL" class="w-full max-w-lg">
        <form wire:submit="append" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Add reference') }}</flux:heading>
                <p class="mt-0.5 font-mono text-xs text-fg-faint">
                    {{ __('appended under :heading', ['heading' => $this::HEADING]) }}
                </p>
            </div>

            <flux:input wire:model="title" :label="__('Title')" :placeholder="__('Sony service manual')" />
            <flux:input wire:model="url" :label="__('URL')" placeholder="https://" />
            <flux:input wire:model="note" :label="__('Note')" :placeholder="__('Pinout on page 42')" />

            @if ($this->existing->isNotEmpty())
                <div>
                    <p class="kicker mb-2 text-fg-faint">{{ __('Already in this doc') }}</p>

                    <ol class="grid gap-1">
                        @foreach ($this->existing as $reference)
                            <li
                                wire:key="reference-{{ $loop->index }}"
                                class="flex items-baseline gap-2 rounded-lg border border-line-input bg-sunken px-3 py-2"
                            >
                                <span class="font-mono text-xs text-fg-faint">{{ $loop->iteration }}</span>
                                <span class="min-w-0 flex-1 truncate text-sm text-fg-soft">{{ $reference }}</span>
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endif

            <div class="flex items-center justify-between gap-2">
                <span class="kicker text-fg-faint">
                    {{ trans_choice(':count reference|:count references', $this->existing->count(), ['count' => $this->existing->count()]) }}
                </span>

                <div class="flex gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary">{{ __('Append') }}</flux:button>
                </div>
            </div>
        </form>
    </flux:modal>
</div>
