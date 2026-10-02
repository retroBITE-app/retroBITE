<?php

use App\Enums\DocTemplate;
use App\Models\Game;
use App\Resources\ConsoleResource;
use App\Services\DocLibrary;
use App\Services\DocLinks;
use App\Support\DocPath;
use Flux\Flux;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    public const MODAL = 'new-doc';

    public string $title = '';

    public string $console = '';

    public string $category = '';

    /** Shown instead of the category chips once "New tag" is pressed. */
    public bool $coiningCategory = false;

    public string $template = 'blank';

    /**
     * The game this doc is being written about, from that game's page: it
     * presets the title and console, and the doc is linked to it on create.
     */
    #[Locked]
    public ?int $gameId = null;

    /** Off where something else opens the modal — the game page's Actions menu. */
    #[Locked]
    public bool $trigger = true;

    public function mount(?int $gameId = null, bool $trigger = true): void
    {
        $this->trigger = $trigger;
        $game = $gameId !== null ? Game::query()->find($gameId) : null;

        if ($game === null) {
            return;
        }

        $this->gameId = $game->id;
        $this->title = $game->title;
        $this->console = ConsoleResource::exists($game->console) ? $game->console : '';
    }

    /**
     * Where this would be written, shown live under the form so the filename
     * is never a surprise. A clash is resolved on write, not here.
     */
    #[Computed]
    public function destination(): string
    {
        $slug = DocPath::slug($this->title);

        return 'docs/'.($this->console !== '' ? $this->console.'/' : '').$slug.'.'.DocPath::DOC_EXTENSION;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function categories(): array
    {
        return app(DocLibrary::class)
            ->suggestedCategories()
            ->map(fn (string $name): array => ['value' => $name, 'label' => $name])
            ->all();
    }

    /** A walkthrough is filed under its tag unless another was picked first. */
    public function updatedTemplate(string $template): void
    {
        if ($template === DocTemplate::Walkthrough->value && $this->category === '') {
            $this->category = 'walkthrough';
        }
    }

    public function create(DocLibrary $library): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:120'],
            'console' => ['nullable', 'string', Rule::in(ConsoleResource::keys())],
            'category' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9 _-]*$/'],
            'template' => ['required', Rule::enum(DocTemplate::class)],
        ]);

        $template = DocTemplate::from(Arr::get($validated, 'template'));

        try {
            $doc = $library->create(
                (string) Arr::get($validated, 'console'),
                (string) Arr::get($validated, 'title'),
                (string) Arr::get($validated, 'category'),
                [],
                $template->body((string) Arr::get($validated, 'title')),
            );
        } catch (\Throwable $e) {
            Log::error('Could not create a document', ['exception' => $e]);
            Flux::toast(variant: 'danger', text: __('Could not create the document.'));

            return;
        }

        $game = $this->gameId !== null ? Game::query()->find($this->gameId) : null;

        // Written about a game: linked to it, and straight into the editor, as
        // nothing on the game page could show an empty doc being written.
        if ($game !== null) {
            app(DocLinks::class)->link($doc->path, $game);
            $this->redirectRoute('docs.index', ['doc' => $doc->path, 'edit' => 1], navigate: true);

            return;
        }

        $this->reset('title', 'category', 'coiningCategory');

        Flux::modal(self::MODAL)->close();
        Flux::toast(variant: 'success', text: __('Document created.'));

        $this->dispatch('doc-written', path: $doc->path);
    }
};
?>

<div>
    @if ($trigger)
        <flux:modal.trigger :name="$this::MODAL">
            <flux:button size="sm" icon="plus">{{ __('New doc') }}</flux:button>
        </flux:modal.trigger>
    @endif

    <flux:modal :name="$this::MODAL" class="w-full max-w-lg">
        <form wire:submit="create" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('New doc') }}</flux:heading>
                <p class="mt-0.5 font-mono text-xs text-fg-faint">{{ config('settings.docs_path') }}</p>
            </div>

            <flux:input wire:model.live.debounce.250ms="title" :label="__('Title')" autofocus />

            <div>
                <p class="kicker mb-2 text-fg-faint">{{ __('Console') }}</p>

                <x-docs.console-picker :selected="$console" />

                <flux:error name="console" />
            </div>

            <div>
                <p class="kicker mb-2 text-fg-faint">{{ __('Category') }}</p>

                @if ($coiningCategory)
                    <flux:input wire:model="category" size="sm" :placeholder="__('modding')" autofocus />
                @else
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($this->categories as ['value' => $value, 'label' => $label])
                            <button
                                type="button"
                                wire:key="category-{{ $value }}"
                                wire:click="$set('category', @js($category === $value ? '' : $value))"
                                @class([
                                    'cursor-pointer rounded-lg border px-2.5 py-1 font-mono text-xs transition-colors',
                                    'border-accent-tint/55 bg-accent-tint/10 text-accent' => $category === $value,
                                    'border-line-input text-fg-dim hover:bg-hover hover:text-fg' => $category !== $value,
                                ])
                            >{{ $label }}</button>
                        @endforeach

                        <button
                            type="button"
                            wire:click="$set('coiningCategory', true)"
                            class="cursor-pointer rounded-lg border border-dashed border-accent-tint/55 px-2.5 py-1 font-mono text-xs text-accent transition-colors hover:bg-accent-tint/10"
                        >{{ __('+ New tag') }}</button>
                    </div>
                @endif

                <flux:error name="category" />
            </div>

            <div>
                <p class="kicker mb-2 text-fg-faint">{{ __('Start from') }}</p>

                <div class="grid gap-1.5">
                    @foreach (DocTemplate::cases() as $option)
                        <button
                            type="button"
                            wire:key="template-{{ $option->value }}"
                            wire:click="$set('template', @js($option->value))"
                            @class([
                                'cursor-pointer rounded-lg border px-3.5 py-2.5 text-left transition-colors',
                                'border-accent-tint/55 bg-accent-tint/10' => $template === $option->value,
                                'border-line-input hover:bg-hover' => $template !== $option->value,
                            ])
                        >
                            <span @class([
                                'block text-sm',
                                'text-accent' => $template === $option->value,
                                'text-fg-soft' => $template !== $option->value,
                            ])>{{ $option->label() }}</span>
                            <span class="mt-0.5 block text-xs text-fg-faint">{{ $option->description() }}</span>
                        </button>
                    @endforeach
                </div>
            </div>

            <div>
                <p class="kicker mb-2 text-fg-faint">{{ __('Writes to') }}</p>
                <p class="rounded-lg border border-line-input bg-sunken px-3 py-2 font-mono text-xs text-fg-dim">
                    {{ $this->destination }}
                </p>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">{{ __('Create doc') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
