<?php

use App\Decryption\DiscKeys;
use App\Exceptions\LibraryPathException;
use App\Models\GameFile;
use Flux\Flux;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * Add a PS3 disc's key: pasted, or read out of a .dkey or .key file in the
 * browser. Checked against the disc itself before it is kept, so a key that
 * does not fit is refused here rather than turning into noise in ps3netsrv or
 * a decrypt that fails. Saved beside the image as <name>.dkey, which is where
 * ps3netsrv looks: the game plays from that moment on.
 *
 * Opened by a `disc-key` window event carrying the file's id, from Tools →
 * Decrypt and from a PS3 game's page; says `disc-key-saved` when it is done.
 */
new class extends Component
{
    public const MODAL = 'disc-key';

    #[Locked]
    public ?int $fileId = null;

    public string $key = '';

    /** Looked up by id so the locked prop is the only thing the client holds. */
    #[Computed]
    public function file(): ?GameFile
    {
        return $this->fileId !== null ? GameFile::query()->with('game')->find($this->fileId) : null;
    }

    /** Opened for one file: a fresh field, no old error. */
    public function open(int $fileId): void
    {
        $this->fileId = $fileId;
        $this->key = '';
        $this->resetErrorBag();

        unset($this->file);
    }

    /** Check the key against the disc, then keep it beside the image. */
    public function save(DiscKeys $keys): void
    {
        $file = $this->file;
        $console = $file?->game->console();

        if ($file === null || $console === null || $keys->decrypterFor($console, $file) === null) {
            return;
        }

        $key = DiscKeys::normalise($this->key);

        if ($key === null) {
            $this->addError('key', __('A disc key is 32 hex digits, such as 0123456789ABCDEF0123456789ABCDEF.'));

            return;
        }

        if (! $keys->fits($file, $key)) {
            $this->addError('key', __('This key does not fit this disc. Check it is the key for this exact version.'));

            return;
        }

        try {
            $keys->save($console, $file, $key);
        } catch (LibraryPathException $e) {
            Log::warning('A disc key could not be saved.', ['file' => $file->id, 'reason' => $e->reason, 'path' => $e->path]);
            Flux::toast(variant: 'danger', text: __('The key could not be saved beside the game.'));

            return;
        }

        Flux::modal(self::MODAL)->close();
        Flux::toast(variant: 'success', text: __('Key saved. It plays over ps3netsrv as it is now; decrypt it to play without the key.'));

        $this->dispatch('disc-key-saved', fileId: $file->id);
    }
}; ?>

<div x-on:disc-key.window="$flux.modal('{{ $this::MODAL }}').show(); $wire.open($event.detail.fileId)">
    <flux:modal :name="$this::MODAL" class="w-full max-w-lg">
        <form wire:submit="save" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Add disc key') }}</flux:heading>
                @if ($this->file !== null)
                    <p class="mt-0.5 truncate font-mono text-xs text-fg-faint">{{ $this->file->filename }}</p>
                @endif
            </div>

            <flux:text>
                {{ __('Each encrypted PS3 disc has its own key, published on the disc\'s Redump page as 32 hex digits or a .dkey file. It is checked against this disc, then kept beside the image where ps3netsrv finds it.') }}
            </flux:text>

            <flux:input
                wire:model="key"
                :label="__('Disc key')"
                placeholder="0123456789ABCDEF0123456789ABCDEF"
                autocomplete="off"
                spellcheck="false"
                class="font-mono"
            />

            {{-- Read here rather than uploaded: a key file is 32 characters of
                 hex, or the same 16 bytes raw, and only the key is sent. --}}
            <div
                x-data="{
                    read(event) {
                        const file = event.target.files[0];
                        event.target.value = '';

                        if (! file || file.size > 256) {
                            return;
                        }

                        file.arrayBuffer().then(function (buffer) {
                            const bytes = new Uint8Array(buffer);
                            const text = new TextDecoder().decode(bytes).trim();
                            // Hex text is 32 characters: 16 bytes is the key raw.
                            $wire.set('key', bytes.length === 16 ? Array.from(bytes, function (byte) { return byte.toString(16).padStart(2, '0'); }).join('') : text);
                        });
                    },
                }"
            >
                <input type="file" accept=".dkey,.key" class="sr-only" x-ref="keyFile" x-on:change="read($event)" />
                <flux:button size="sm" variant="ghost" icon="document-arrow-up" x-on:click="$refs.keyFile.click()">
                    {{ __('Load a .dkey file') }}
                </flux:button>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" icon="key">{{ __('Check and save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
