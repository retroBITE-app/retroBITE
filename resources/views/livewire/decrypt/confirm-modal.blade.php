<?php

use App\Decryption\DiscKeys;
use App\Enums\DecryptState;
use App\Models\GameFile;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * Ask before decrypting: it replaces the encrypted image for good and deletes
 * the .dkey beside it, and neither can be undone. One confirm for Tools →
 * Decrypt's picks and a game page's single file, so both say the same thing.
 *
 * Opened by a `decrypt-confirm` window event carrying `fileIds`; says
 * `decrypt-queued` once the images are in the queue.
 */
new class extends Component
{
    public const MODAL = 'decrypt-confirm';

    /** How many file names the modal lists before it counts the rest. */
    private const LISTED = 5;

    /** @var list<int> */
    #[Locked]
    public array $fileIds = [];

    /**
     * The images asked about, looked up by id so the locked list is all the client holds.
     *
     * @return Collection<int, GameFile>
     */
    #[Computed]
    public function files(): Collection
    {
        return GameFile::query()->with('game')->whereKey($this->fileIds)->orderBy('path')->get();
    }

    /**
     * The asked-about images already waiting or running in the decrypt queue, by id.
     *
     * @return list<int>
     */
    #[Computed]
    public function decrypting(): array
    {
        return app(DiscKeys::class)->decrypting($this->fileIds);
    }

    /**
     * Opened for a new set of images.
     *
     * @param  array<int, int|string>  $fileIds
     */
    public function open(array $fileIds): void
    {
        $this->fileIds = collect($fileIds)
            ->map(function (int|string $id): int {
                return (int) $id;
            })
            ->filter()
            ->values()
            ->all();

        unset($this->files, $this->decrypting);
    }

    /** Queue them, once: the modal closes before a second click can land. */
    public function decrypt(DiscKeys $keys): void
    {
        ['queued' => $queued, 'skipped' => $skipped] = $keys->queueDecryption($this->files);

        Flux::modal(self::MODAL)->close();
        $this->fileIds = [];

        Flux::toast(
            variant: $queued === [] ? 'danger' : 'success',
            text: $skipped === 0
                ? trans_choice(':count image queued for decryption.|:count images queued for decryption.', count($queued), ['count' => count($queued)])
                : __(':queued queued. :skipped could not be decrypted now: already in the queue, or without its key.', ['queued' => count($queued), 'skipped' => $skipped]),
        );

        $this->dispatch('conversion-queued');
        $this->dispatch('decrypt-queued');
    }
}; ?>

<x-modal
    :name="$this::MODAL"
    event="decrypt-confirm"
    then="$wire.open($event.detail.fileIds)"
    :heading="trans_choice('Decrypt this image?|Decrypt :count images?', $this->files->count(), ['count' => $this->files->count()])"
>
    @if ($this->files->isNotEmpty())
        <x-preview-list :total="$this->files->count()" :limit="$this::LISTED">
            @foreach ($this->files->take($this::LISTED) as $file)
                <li wire:key="confirm-{{ $file->id }}" class="flex items-center gap-2 px-3 py-2">
                    <span class="min-w-0 truncate font-mono text-xs text-fg-soft">{{ $file->filename }}</span>
                    @if (in_array($file->id, $this->decrypting, true))
                        <x-decrypt.state :state="DecryptState::Decrypting" />
                    @endif
                </li>
            @endforeach
        </x-preview-list>
    @endif

    <div class="space-y-2 text-sm text-fg-muted">
        <p>{{ __('The decrypted image replaces the encrypted one, under the same name. The encrypted image is gone afterwards.') }}</p>
        <p>{{ __('The .dkey beside it is deleted too: left there, ps3netsrv would decrypt the decrypted image again. The key itself stays on record and is shown on the game\'s page.') }}</p>
        <p class="text-warn">{{ __('This cannot be undone.') }}</p>
        @if ($this->decrypting !== [])
            <p>{{ trans_choice('One of these is already being decrypted and is left as it is.|:count of these are already being decrypted and are left as they are.', count($this->decrypting), ['count' => count($this->decrypting)]) }}</p>
        @endif
    </div>

    <x-slot:footer>
        <flux:button variant="danger" icon="lock-open" wire:click="decrypt" wire:loading.attr="disabled" :disabled="$this->files->count() === count($this->decrypting)">
            {{ __('Decrypt') }}
        </flux:button>
    </x-slot:footer>
</x-modal>
