<?php

use App\Resources\ConsoleResource;
use App\Services\DocArchive;
use App\Services\DocLibrary;
use Flux\Flux;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public const MODAL = 'import-doc';

    /** Prose, or a bundle of prose and photographs. Neither is large. */
    public const MAX_KILOBYTES = 32768;

    public ?TemporaryUploadedFile $upload = null;

    public string $title = '';

    public string $console = '';

    public string $category = '';

    /** Attachments found inside an uploaded archive. */
    public int $attachments = 0;

    /**
     * Read the file as soon as it arrives and prefill from whatever it carries,
     * so an export from elsewhere lands filed correctly.
     */
    public function updatedUpload(): void
    {
        $this->validate(['upload' => $this->uploadRules()]);

        try {
            $bundle = $this->bundle();
        } catch (\Throwable $e) {
            Log::warning('Could not read an imported file', ['exception' => $e]);
            $this->reset('upload', 'attachments');
            $this->addError('upload', __('That file could not be read.'));

            return;
        }

        $parsed = app(DocLibrary::class)->inspect(Arr::get($bundle, 'name'), Arr::get($bundle, 'markdown'));
        $console = (string) Arr::get($parsed, 'console');

        $this->title = Arr::get($parsed, 'title');
        $this->console = ConsoleResource::exists($console) ? $console : '';
        $this->category = Arr::get($parsed, 'category');
        $this->attachments = count(Arr::get($bundle, 'media', []));
    }

    #[Computed]
    public function ready(): bool
    {
        return $this->upload instanceof TemporaryUploadedFile;
    }

    /**
     * Whether a bundle can be offered at all. ext-zip is in the php-fpm image,
     * but a host PHP running artisan may not have it.
     */
    #[Computed]
    public function archives(): bool
    {
        return app(DocArchive::class)->available();
    }

    public function import(DocLibrary $library): void
    {
        $validated = $this->validate([
            'upload' => $this->uploadRules(),
            'title' => ['required', 'string', 'max:120'],
            'console' => ['nullable', 'string', Rule::in(ConsoleResource::keys())],
            'category' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9 _-]*$/'],
        ]);

        try {
            $bundle = $this->bundle();
            $parsed = $library->inspect(Arr::get($bundle, 'name'), Arr::get($bundle, 'markdown'));

            $doc = $library->create(
                (string) Arr::get($validated, 'console'),
                (string) Arr::get($validated, 'title'),
                (string) Arr::get($validated, 'category'),
                Arr::get($parsed, 'tags'),
                Arr::get($parsed, 'body'),
                Arr::get($bundle, 'media'),
            );
        } catch (\Throwable $e) {
            Log::error('Could not import a document', ['exception' => $e]);
            Flux::toast(variant: 'danger', text: __('Could not import that file.'));

            return;
        }

        $imported = count(Arr::get($bundle, 'media', []));

        $this->reset('upload', 'title', 'console', 'category', 'attachments');

        Flux::modal(self::MODAL)->close();
        Flux::toast(variant: 'success', text: $imported === 0
            ? __('Document imported.')
            : trans_choice('Document imported with :count image.|Document imported with :count images.', $imported, ['count' => $imported]));

        $this->dispatch('doc-written', path: $doc->path);
    }

    /**
     * The uploaded file as a document plus its attachments, whether it arrived
     * as one markdown file or as an exported archive.
     *
     * @return array{name: string, markdown: string, media: array<string, string>}
     */
    private function bundle(): array
    {
        $file = $this->upload;

        if (! $file instanceof TemporaryUploadedFile) {
            throw new \RuntimeException('Nothing uploaded');
        }

        $name = $file->getClientOriginalName();

        if (Str::lower($file->getClientOriginalExtension()) !== 'zip') {
            return ['name' => $name, 'markdown' => (string) file_get_contents($file->getRealPath()), 'media' => []];
        }

        return app(DocArchive::class)->read($file->getRealPath());
    }

    /**
     * @return array<int, string>
     */
    private function uploadRules(): array
    {
        $formats = $this->archives() ? 'md,markdown,txt,zip' : 'md,markdown,txt';

        return ['required', 'file', 'mimes:'.$formats, 'max:'.self::MAX_KILOBYTES];
    }
};
?>

<div>
    <flux:modal.trigger :name="$this::MODAL">
        <flux:button size="sm" variant="ghost" icon="arrow-up-tray">{{ __('Import') }}</flux:button>
    </flux:modal.trigger>

    <flux:modal :name="$this::MODAL" class="w-full max-w-lg">
        <form wire:submit="import" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $this->archives ? __('Import a doc') : __('Import markdown') }}</flux:heading>
                <p class="mt-0.5 text-xs text-fg-faint">
                    {{ $this->archives
                        ? __('A .md file, or a .zip exported from here with its images alongside.')
                        : __('Front matter is read if the file has any; otherwise the first heading becomes the title.') }}
                </p>
            </div>

            <label class="grid cursor-pointer place-items-center gap-1 rounded-xl border border-dashed border-line-input px-6 py-8 text-center transition-colors hover:bg-hover">
                <input
                    type="file"
                    wire:model="upload"
                    accept="{{ $this->archives ? '.md,.markdown,.zip,text/markdown,text/plain,application/zip' : '.md,.markdown,text/markdown,text/plain' }}"
                    class="sr-only"
                />

                <flux:icon.document-text class="size-6 text-fg-faint" />

                <span class="text-sm text-fg-soft">
                    {{ $upload?->getClientOriginalName() ?? ($this->archives ? __('Choose or drop a .md or .zip') : __('Choose or drop a .md file')) }}
                </span>

                @if ($attachments > 0)
                    <span class="font-mono text-xs text-accent">
                        {{ trans_choice(':count image in the archive|:count images in the archive', $attachments, ['count' => $attachments]) }}
                    </span>
                @endif
            </label>

            <flux:error name="upload" />

            @if ($this->ready)
                <flux:input wire:model="title" :label="__('Title')" />

                <div>
                    <p class="kicker mb-2 text-fg-faint">{{ __('Console') }}</p>
                    <p class="mb-2 text-xs text-fg-faint">{{ __('Decides the folder it is written to, and where its images go.') }}</p>

                    <x-docs.console-picker :selected="$console" key-prefix="import-console" />

                    <flux:error name="console" />
                </div>

                <flux:input wire:model="category" :label="__('Category')" :placeholder="__('modding')" />

                <div>
                    <p class="kicker mb-2 text-fg-faint">{{ __('Writes to') }}</p>
                    <p class="rounded-lg border border-line-input bg-sunken px-3 py-2 font-mono text-xs text-fg-dim">
                        docs/{{ $console !== '' ? $console.'/' : '' }}{{ App\Support\DocPath::slug($title) }}.md
                    </p>
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary" :disabled="! $this->ready">{{ __('Import') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
