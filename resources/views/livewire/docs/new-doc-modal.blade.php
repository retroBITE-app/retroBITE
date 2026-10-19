<?php

use App\Enums\DocTemplate;
use App\Models\Game;
use App\Resources\ConsoleResource;
use App\Services\DocLibrary;
use App\Services\DocLinks;
use App\Support\DocPath;
use Flux\Flux;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
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
            ->map(function (string $name): array {
                return ['value' => $name, 'label' => $name];
            })
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

    <x-modal :name="$this::MODAL" submit="create" :heading="__('New doc')" :subheading="config('settings.docs_path')" mono>
        <flux:input wire:model.live.debounce.250ms="title" :label="__('Title')" autofocus />

        <x-field-group :label="__('Console')">
            <x-docs.console-picker :selected="$console" />

            <flux:error name="console" />
        </x-field-group>

        <x-field-group :label="__('Category')">
            @if ($coiningCategory)
                <flux:input wire:model="category" size="sm" :placeholder="__('modding')" autofocus />
            @else
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($this->categories as ['value' => $value, 'label' => $label])
                        <x-chip
                            mono
                            :active="$category === $value"
                            wire:key="category-{{ $value }}"
                            wire:click="$set('category', {{ Js::from($category === $value ? '' : $value) }})"
                        >{{ $label }}</x-chip>
                    @endforeach

                    <x-chip dashed mono wire:click="$set('coiningCategory', true)">{{ __('+ New tag') }}</x-chip>
                </div>
            @endif

            <flux:error name="category" />
        </x-field-group>

        <x-field-group :label="__('Start from')">
            <div class="grid gap-1.5">
                @foreach (DocTemplate::cases() as $option)
                    <x-option-card
                        :active="$template === $option->value"
                        :label="$option->label()"
                        :description="$option->description()"
                        wire:key="template-{{ $option->value }}"
                        wire:click="$set('template', {{ Js::from($option->value) }})"
                    />
                @endforeach
            </div>
        </x-field-group>

        <x-path-preview :value="$this->destination" />

        <x-slot:footer>
            <flux:button type="submit" variant="primary">{{ __('Create doc') }}</flux:button>
        </x-slot:footer>
    </x-modal>
</div>
