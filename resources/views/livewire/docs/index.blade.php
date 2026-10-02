<?php

use App\Models\Game;
use App\Resources\ConsoleResource;
use App\Resources\DocResource;
use App\Services\DocLibrary;
use App\Services\DocLinks;
use App\Services\DocRenderer;
use App\Support\DocPath;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Documents')] class extends Component
{
    /** Relative path of the open document, in the URL so a doc can be linked. */
    #[Url(as: 'doc', except: '')]
    public string $path = '';

    #[Url(as: 'q', except: '')]
    public string $query = '';

    #[Url(as: 'filter', except: '')]
    public string $filter = '';

    /**
     * An earlier version being read, named by the moment it was superseded,
     * in the URL so it can be linked. 0 is the document as it is now.
     */
    #[Url(as: 'rev', except: 0)]
    public int $revision = 0;

    /** preview | markdown */
    public string $mode = 'preview';

    public bool $editing = false;

    /** The body being edited. Only the body: front matter is owned by the UI. */
    public string $draft = '';

    /**
     * Open the first document when none was named, so the page is never blank
     * while the library has something in it.
     */
    public function mount(DocLibrary $library): void
    {
        if ($this->current() === null) {
            $this->path = (string) $library->search($this->query, $this->filter)->value('path', '');
        }

        // ?edit=1, from a game page's Edit or a doc just written about a game.
        if (request()->boolean('edit')) {
            $this->edit();
        }
    }

    /**
     * @return Collection<int, DocResource>
     */
    #[Computed]
    public function docs(): Collection
    {
        return app(DocLibrary::class)->search($this->query, $this->filter);
    }

    /** The open document, or the earlier version of it being read. */
    #[Computed]
    public function current(): ?DocResource
    {
        $library = app(DocLibrary::class);
        $doc = $library->find($this->path);

        if ($doc === null || $this->revision === 0) {
            return $doc;
        }

        return $library->revision($doc->path, $this->revision) ?? $doc;
    }

    /**
     * The games the open document is about.
     *
     * @return Collection<int, Game>
     */
    #[Computed]
    public function linkedGames(): Collection
    {
        return $this->current() !== null ? app(DocLinks::class)->gamesFor($this->path) : collect();
    }

    /**
     * The open document's earlier versions, newest first, in milliseconds.
     *
     * @return Collection<int, int>
     */
    #[Computed]
    public function history(): Collection
    {
        $library = app(DocLibrary::class);

        return $library->find($this->path) !== null ? $library->revisions($this->path) : collect();
    }

    #[Computed]
    public function html(): string
    {
        $doc = $this->current();

        return $doc instanceof DocResource ? app(DocRenderer::class)->render($doc) : '';
    }

    /**
     * The filter row: every console and every category that has documents.
     *
     * @return Collection<int, array{value: string, label: string}>
     */
    #[Computed]
    public function chips(): Collection
    {
        $library = app(DocLibrary::class);

        return $library->consoles()
            ->map(fn (string $key): array => [
                'value' => DocLibrary::consoleFilter($key),
                'label' => ConsoleResource::make($key)?->name ?? $key,
            ])
            ->concat($library->categories()->map(fn (string $name): array => [
                'value' => DocLibrary::categoryFilter($name),
                'label' => Str::headline($name),
            ]))
            ->values();
    }

    /**
     * Where this document's attachments live, relative to the docs root.
     */
    #[Computed]
    public function mediaDirectory(): string
    {
        $doc = $this->current();

        return $doc instanceof DocResource
            ? app(DocPath::class)->mediaDirectory($doc->path)
            : '';
    }

    public function select(string $path): void
    {
        $this->path = $path;
        $this->revision = 0;
        $this->editing = false;
        $this->mode = 'preview';
    }

    public function edit(): void
    {
        $doc = $this->current();

        // An earlier version is read, not edited: restoring it comes first.
        if (! $doc instanceof DocResource || $this->revision !== 0) {
            return;
        }

        $this->draft = $doc->body;
        $this->editing = true;
        $this->mode = 'markdown';
    }

    public function cancel(): void
    {
        $this->editing = false;
        $this->mode = 'preview';
        $this->draft = '';
    }

    public function save(DocLibrary $library): void
    {
        try {
            $library->save($this->path, $this->draft);
        } catch (\Throwable $e) {
            // The message can name a filesystem path, so it is logged and the
            // user is told only that it failed.
            Log::error('Could not save a document', ['path' => $this->path, 'exception' => $e]);
            Flux::toast(variant: 'danger', text: __('Could not save the document.'));

            return;
        }

        $this->cancel();
        $this->forgetReads();

        Flux::toast(variant: 'success', text: __('Document saved.'));
    }

    public function unlinkGame(int $gameId): void
    {
        $game = Game::query()->find($gameId);

        if ($game !== null) {
            app(DocLinks::class)->unlink($this->path, $game);
        }

        unset($this->linkedGames);
    }

    /** A game was linked from the dialog: show it in the row. */
    #[On('doc-linked')]
    public function refreshLinks(): void
    {
        unset($this->linkedGames);
    }

    /** Read an earlier version; 0 goes back to the current one. */
    public function viewRevision(int $timestamp): void
    {
        $this->revision = $this->history->contains($timestamp) ? $timestamp : 0;
        $this->cancel();
        unset($this->current, $this->html);
    }

    /** Make the version being read the current one, keeping the current one as a revision. */
    public function restoreRevision(DocLibrary $library): void
    {
        if ($this->revision === 0) {
            return;
        }

        try {
            $library->restore($this->path, $this->revision);
        } catch (\Throwable $e) {
            Log::error('Could not restore a document', ['path' => $this->path, 'revision' => $this->revision, 'exception' => $e]);
            Flux::toast(variant: 'danger', text: __('Could not restore that version.'));

            return;
        }

        $this->revision = 0;
        $this->forgetReads();

        Flux::toast(variant: 'success', text: __('Version restored. The one it replaced is in the history.'));
    }

    public function delete(DocLibrary $library): void
    {
        try {
            $library->delete($this->path);
        } catch (\Throwable $e) {
            Log::error('Could not delete a document', ['path' => $this->path, 'exception' => $e]);
            Flux::toast(variant: 'danger', text: __('Could not delete the document.'));

            return;
        }

        $this->path = '';
        $this->revision = 0;
        $this->cancel();
        $this->forgetReads();

        Flux::toast(variant: 'success', text: __('Document deleted.'));
    }

    /**
     * A modal wrote something: drop the memoised reads and open what it made.
     */
    #[On('doc-written')]
    public function refresh(?string $path = null): void
    {
        $this->forgetReads();

        if ($path !== null) {
            $this->select($path);
        }
    }

    /**
     * Drop every memoised read, so the next render sees the new tree.
     */
    private function forgetReads(): void
    {
        unset($this->docs, $this->current, $this->history, $this->linkedGames, $this->html, $this->chips, $this->mediaDirectory);
    }

    /**
     * A dialog rewrote the whole draft, rather than splicing into it.
     */
    #[On('doc-body-replaced')]
    public function replaceBody(string $body): void
    {
        $this->draft = $body;
    }

    /**
     * Splice a snippet into the draft. Dispatched by the insert dialogs, which
     * do not know where the caret is — the browser does, so it lands there.
     */
    #[On('doc-insert')]
    public function insert(string $snippet): void
    {
        $this->dispatch('doc-insert-at-cursor', snippet: $snippet);
    }
};
?>

<section class="w-full" x-data>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="kicker mb-1.5 text-fg-faint">
                {{ __('Knowledge base') }} · {{ trans_choice(':count doc|:count docs', $this->docs->count(), ['count' => $this->docs->count()]) }}
            </p>
            <h1 class="text-display font-medium tracking-display text-fg-bright">{{ __('Documents') }}</h1>
        </div>

        <div class="flex items-center gap-2">
            <x-search-field wire:model.live.debounce.300ms="query" :placeholder="__('Search notes')" class="w-64" />

            <livewire:docs.import-modal />
            <livewire:docs.new-doc-modal />
        </div>
    </div>

    @if ($this->chips->isNotEmpty())
        <div class="mb-5 flex flex-wrap gap-1.5">
            <button
                type="button"
                wire:click="$set('filter', '')"
                @class([
                    'cursor-pointer rounded-lg border px-2.75 py-1 text-xs transition-colors',
                    'border-accent-tint/55 bg-accent-tint/10 text-accent' => $filter === '',
                    'border-line-input text-fg-dim hover:bg-hover hover:text-fg' => $filter !== '',
                ])
            >{{ __('All') }}</button>

            @foreach ($this->chips as ['value' => $value, 'label' => $label])
                <button
                    type="button"
                    wire:click="$set('filter', @js($value))"
                    wire:key="chip-{{ $value }}"
                    @class([
                        'cursor-pointer rounded-lg border px-2.75 py-1 text-xs transition-colors',
                        'border-accent-tint/55 bg-accent-tint/10 text-accent' => $filter === $value,
                        'border-line-input text-fg-dim hover:bg-hover hover:text-fg' => $filter !== $value,
                    ])
                >{{ $label }}</button>
            @endforeach
        </div>
    @endif

    <div class="flex flex-col gap-4 lg:flex-row lg:items-start">
        <div class="min-w-0 flex-1 rounded-xl border border-line bg-surface">
            @include('livewire.docs.partials.viewer')
        </div>

        <aside class="w-full shrink-0 lg:w-[19rem]">
            @include('livewire.docs.partials.rail')
        </aside>
    </div>
</section>
