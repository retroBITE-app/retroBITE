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

    <x-modal :name="$this::MODAL" submit="insert" :heading="__('Insert media')" :subheading="$path" mono>
        <x-pill-choice
            :options="['image' => __('Image'), 'youtube' => __('YouTube')]"
            :label="__('Media type')"
            model="tab"
            :value="$tab"
            size="sm"
            full
        />

        @if ($tab === 'image')
            <x-file-drop
                icon="photo"
                :label="$upload?->getClientOriginalName() ?? __('Choose or drop an image')"
                :hint="__(':formats · stored beside the doc and bundled on export', ['formats' => implode(', ', DocPath::MEDIA_EXTENSIONS)])"
                accept="image/*"
                wire:model="upload"
            />

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

        <x-path-preview :label="__('Inserts')" :value="$this->snippet" />

        <x-slot:footerStart>
            <span class="kicker text-fg-faint">{{ __('Inserts at cursor') }}</span>
        </x-slot:footerStart>

        <x-slot:footer>
            <flux:button type="submit" variant="primary">{{ __('Insert') }}</flux:button>
        </x-slot:footer>
    </x-modal>
</div>
