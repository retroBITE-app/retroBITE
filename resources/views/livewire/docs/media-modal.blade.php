<?php

use App\Services\DocLibrary;
use App\Support\DocPath;
use App\Support\Markdown\VideoEmbed;
use Flux\Flux;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public const MODAL = 'insert-media';

    /** Largest attachment accepted, in kilobytes. Well under nginx's 64M. */
    public const MAX_KILOBYTES = 8192;

    public string $path = '';

    /** image | youtube */
    public string $tab = 'image';

    public ?TemporaryUploadedFile $upload = null;

    public string $caption = '';

    public string $url = '';

    /**
     * The markdown this will splice in, shown before it is inserted so there
     * is no surprise about what lands in the file.
     */
    #[Computed]
    public function snippet(): string
    {
        if ($this->tab === 'youtube') {
            return VideoEmbed::idFrom($this->url) === null ? '' : '@video['.$this->url.']';
        }

        // The name is the image's hash, known once it is stored.
        return $this->upload instanceof TemporaryUploadedFile
            ? '!['.$this->caption.']('.DocPath::mediaLink('…').')'
            : '';
    }

    #[Computed]
    public function videoId(): ?string
    {
        return VideoEmbed::idFrom($this->url);
    }

    public function insert(DocLibrary $library): void
    {
        if ($this->tab === 'youtube') {
            $this->validate(['url' => ['required', 'url']]);

            if ($this->videoId() === null) {
                $this->addError('url', __('That is not a YouTube link.'));

                return;
            }

            $this->finish($this->snippet());

            return;
        }

        $this->validate([
            'upload' => ['required', 'image', 'mimes:'.implode(',', DocPath::MEDIA_EXTENSIONS), 'max:'.self::MAX_KILOBYTES],
        ]);

        try {
            $filename = $library->storeMedia($this->path, (string) file_get_contents($this->upload->getRealPath()));
        } catch (\Throwable $e) {
            Log::error('Could not store an attachment', ['path' => $this->path, 'exception' => $e]);
            Flux::toast(variant: 'danger', text: __('Could not store that image.'));

            return;
        }

        if ($filename === null) {
            $this->addError('upload', __('That file is not an image this can keep.'));

            return;
        }

        $this->finish('!['.$this->caption.']('.DocPath::mediaLink($filename).')');
    }

    private function finish(string $snippet): void
    {
        $this->dispatch('doc-insert', snippet: $snippet);
        $this->reset('upload', 'caption', 'url');

        Flux::modal(self::MODAL)->close();
    }
};
?>

<div>
    <flux:modal.trigger :name="$this::MODAL">
        <flux:button size="xs" variant="ghost" icon="paper-clip">{{ __('Media') }}</flux:button>
    </flux:modal.trigger>

    <flux:modal :name="$this::MODAL" class="w-full max-w-lg">
        <form wire:submit="insert" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Insert media') }}</flux:heading>
                <p class="mt-0.5 font-mono text-xs text-fg-faint">{{ $path }}</p>
            </div>

            <div class="grid grid-cols-2 gap-0.5 rounded-lg border border-line-input p-0.5">
                @foreach (['image' => __('Image'), 'youtube' => __('YouTube')] as $value => $label)
                    <button
                        type="button"
                        wire:click="$set('tab', '{{ $value }}')"
                        @class([
                            'cursor-pointer rounded-md px-3 py-1.5 text-sm transition-colors',
                            'bg-accent-tint/15 text-accent' => $tab === $value,
                            'text-fg-dim hover:bg-hover hover:text-fg' => $tab !== $value,
                        ])
                    >{{ $label }}</button>
                @endforeach
            </div>

            @if ($tab === 'image')
                <label class="grid cursor-pointer place-items-center gap-1 rounded-xl border border-dashed border-line-input px-6 py-8 text-center transition-colors hover:bg-hover">
                    <input type="file" wire:model="upload" accept="image/*" class="sr-only" />

                    <flux:icon.photo class="size-6 text-fg-faint" />

                    <span class="text-sm text-fg-soft">
                        {{ $upload?->getClientOriginalName() ?? __('Choose or drop an image') }}
                    </span>
                    <span class="font-mono text-xs text-fg-faint">
                        {{ __(':formats · stored beside the doc and bundled on export', ['formats' => implode(', ', DocPath::MEDIA_EXTENSIONS)]) }}
                    </span>
                </label>

                <flux:error name="upload" />

                <flux:input wire:model.live.debounce.250ms="caption" :label="__('Caption')" :placeholder="__('Pot location on the drive board')" />
            @else
                <flux:input wire:model.live.debounce.250ms="url" :label="__('YouTube URL')" placeholder="https://youtu.be/…" />

                @if ($this->videoId !== null)
                    <div class="doc-video aspect-video overflow-hidden rounded-xl border border-line">
                        <iframe
                            src="https://www.youtube-nocookie.com/embed/{{ $this->videoId }}"
                            title="{{ __('Preview') }}"
                            class="size-full border-0"
                            loading="lazy"
                            referrerpolicy="strict-origin-when-cross-origin"
                            allowfullscreen
                        ></iframe>
                    </div>
                @endif

                <p class="text-xs text-fg-faint">
                    {{ __('Embeds render in preview and stay as plain text in the markdown, so the file is still readable anywhere.') }}
                </p>
            @endif

            <div>
                <p class="kicker mb-2 text-fg-faint">{{ __('Inserts') }}</p>
                <p class="truncate rounded-lg border border-line-input bg-sunken px-3 py-2 font-mono text-xs text-fg-dim">
                    {{ $this->snippet !== '' ? $this->snippet : '—' }}
                </p>
            </div>

            <div class="flex items-center justify-between gap-2">
                <span class="kicker text-fg-faint">{{ __('Inserts at cursor') }}</span>

                <div class="flex gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary">{{ __('Insert') }}</flux:button>
                </div>
            </div>
        </form>
    </flux:modal>
</div>
