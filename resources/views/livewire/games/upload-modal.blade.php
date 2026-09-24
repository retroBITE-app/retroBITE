<?php

use App\Exceptions\UploadRejected;
use App\Models\ConsoleSourceFolder;
use App\Services\RomUploads;
use App\Support\Console;
use Flux\Flux;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/*
 * ROM uploads onto one console's shelf.
 *
 * The bytes go straight to the chunk route; this component is the rest of it.
 * begin() settles whether the file may come and where it will land, finish()
 * moves it into place, uploaded() reports the batch. Nothing is scanned here:
 * the files are on disk, and the shelf's Scan folder brings them in when
 * somebody asks. The first three are renderless: the file list is the uploader's, in Alpine, and a morph between
 * chunks would only fight it.
 */
new class extends Component
{
    public const MODAL = 'upload-roms';

    #[Locked]
    public string $console;

    /** Looked up by key so the locked prop is the only thing the client holds. */
    #[Computed]
    public function target(): Console
    {
        $console = Console::tryFrom($this->console);

        abort_if($console === null, 404);

        return $console;
    }

    /**
     * Where the active layout reads games from, as destination => label.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function destinations(): array
    {
        return ConsoleSourceFolder::destinationsFor($this->target);
    }

    /**
     * The extensions this console plays, lowercase, for the picker and the
     * browser's own check.
     *
     * @return string[]
     */
    #[Computed]
    public function extensions(): array
    {
        return collect($this->target->fileExtensions)
            ->map(function (string $extension): string {
                return strtolower($extension);
            })
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** The picker's accept attribute, e.g. ".bin,.iso". A hint only: begin() decides. */
    #[Computed]
    public function accept(): string
    {
        return collect($this->extensions)
            ->map(function (string $extension): string {
                return '.'.$extension;
            })
            ->implode(',');
    }

    /**
     * File names the library ignores, lowercase.
     *
     * @return string[]
     */
    #[Computed]
    public function excluded(): array
    {
        return array_map('strtolower', $this->target->excludeFiles);
    }

    /**
     * Ask to upload one file. Answers where to send it, or why not.
     *
     * @return array{ok: bool, id?: string, url?: string, chunk?: int, message?: string}
     */
    #[Renderless]
    public function begin(string $filename, int $size, string $destination, RomUploads $uploads): array
    {
        try {
            $upload = $uploads->begin($this->target, (int) Auth::id(), $filename, $size, $destination);
        } catch (UploadRejected $e) {
            return ['ok' => false, 'message' => $e->reason->label()];
        }

        return [
            'ok' => true,
            'id' => $upload->id,
            'url' => route('uploads.chunk', ['upload' => $upload->id]),
            'chunk' => RomUploads::CHUNK_BYTES,
        ];
    }

    /**
     * Move a fully sent file into the console's folder.
     *
     * @return array{ok: bool, message?: string}
     */
    #[Renderless]
    public function finish(string $id, RomUploads $uploads): array
    {
        try {
            $uploads->finish($id, (int) Auth::id());
        } catch (UploadRejected $e) {
            return ['ok' => false, 'message' => $e->reason->label()];
        }

        return ['ok' => true];
    }

    /** Give up an upload the browser stopped, staged bytes and all. */
    #[Renderless]
    public function cancel(string $id, RomUploads $uploads): void
    {
        $uploads->abandon($id, (int) Auth::id());
    }

    /** Report a finished batch. Queues nothing: Scan folder is how files join the shelf. */
    public function uploaded(int $count): void
    {
        if ($count < 1) {
            return;
        }

        Flux::toast(variant: 'success', text: trans_choice(
            ':count file uploaded to :console. Run Scan folder to add it to the shelf.|:count files uploaded to :console. Run Scan folder to add them to the shelf.',
            $count,
            ['console' => $this->target->name],
        ));
    }
}; ?>

<div x-on:upload-roms.window="$flux.modal('{{ $this::MODAL }}').show()">
    <flux:modal :name="$this::MODAL" class="w-full max-w-2xl">
        <div
            x-data="romUpload(@js([
                'extensions' => $this->extensions,
                'excluded' => $this->excluded,
                'destination' => (string) array_key_first($this->destinations),
                'csrf' => csrf_token(),
                'messages' => [
                    'wrongType' => __('Not a file type :console plays.', ['console' => $this->target->name]),
                    'excluded' => __('A file the library ignores.'),
                    'failed' => __('The upload failed. Try it again.'),
                    'cancelled' => __('Cancelled.'),
                    'expired' => __('Your session has expired. Reload the page and try again.'),
                ],
            ]))"
            class="flex flex-col gap-5"
        >
            <div>
                <flux:heading size="lg">{{ __('Upload to :console', ['console' => $this->target->name]) }}</flux:heading>
                <flux:text class="mt-1">
                    {{ __('Files go where this console\'s layout reads games from. Run Scan folder afterwards to add them to the shelf.') }}
                </flux:text>
            </div>

            {{-- A choice only where the layout offers one. Open PS2 Loader
                 splits DVD from CD images; every other layout reads the
                 console's own folder, and asking about that would be asking
                 nothing. --}}
            @if (count($this->destinations) > 1)
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="kicker text-fg-dim">{{ __('Destination') }}</p>

                    <div role="radiogroup" aria-label="{{ __('Destination') }}" class="flex shrink-0 items-center gap-0.5 rounded-lg border border-line-input p-0.5">
                        @foreach ($this->destinations as $value => $label)
                            <button
                                type="button"
                                role="radio"
                                x-bind:aria-checked="destination === @js((string) $value) ? 'true' : 'false'"
                                x-bind:disabled="running"
                                x-on:click="destination = @js((string) $value)"
                                class="cursor-pointer rounded-md px-2.75 py-1 font-mono text-xs transition-colors disabled:cursor-not-allowed"
                                x-bind:class="destination === @js((string) $value)
                                    ? 'bg-accent-tint/15 text-accent'
                                    : 'text-fg-dim hover:bg-hover hover:text-fg'"
                            >{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
            @else
                <p class="text-sm text-fg-muted">
                    {{ __('Uploads to') }}
                    <span class="font-mono text-fg-soft">{{ Arr::first($this->destinations) }}</span>
                </p>
            @endif

            <label
                class="grid cursor-pointer place-items-center gap-1 rounded-xl border border-dashed border-line-input px-6 py-8 text-center transition-colors hover:bg-hover"
                x-on:dragover.prevent
                x-on:drop.prevent="drop($event)"
            >
                <input
                    type="file"
                    multiple
                    accept="{{ $this->accept }}"
                    class="sr-only"
                    x-bind:disabled="running"
                    x-on:change="pick($event)"
                />

                <flux:icon.arrow-up-tray class="size-6 text-fg-faint" />

                <span class="text-sm text-fg-soft">{{ __('Choose or drop game files') }}</span>
                <span class="font-mono text-xs text-fg-faint">{{ implode(' · ', $this->extensions) }}</span>
            </label>

            <template x-if="rejected.length > 0">
                <ul class="flex flex-col gap-1 rounded-lg border border-danger/40 px-3.5 py-2.5 text-sm">
                    <template x-for="entry in rejected" :key="entry.name">
                        <li class="flex flex-wrap items-baseline justify-between gap-x-3">
                            <span class="min-w-0 truncate font-mono text-xs text-fg-soft" x-text="entry.name"></span>
                            <span class="text-xs text-danger" x-text="entry.message"></span>
                        </li>
                    </template>
                </ul>
            </template>

            <template x-if="files.length > 0">
                <ul class="flex max-h-72 flex-col divide-y divide-line overflow-y-auto rounded-lg border border-line">
                    <template x-for="entry in files" :key="entry.key">
                        <li class="flex flex-col gap-1.75 px-3.5 py-2.5">
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="min-w-0 truncate font-mono text-xs text-fg-soft" x-text="entry.name"></span>

                                <span class="flex shrink-0 items-center gap-2 font-mono text-xs">
                                    <span class="text-fg-faint" x-show="entry.status === 'queued'" x-text="bytes(entry.size)"></span>
                                    <span class="text-fg-muted" x-show="entry.status === 'uploading'" x-text="bytes(entry.sent) + ' / ' + bytes(entry.size) + ' · ' + percent(entry) + '%'"></span>
                                    <span class="text-accent" x-show="entry.status === 'done'">{{ __('Uploaded') }}</span>
                                    <span class="text-danger" x-show="entry.status === 'failed'">{{ __('Failed') }}</span>
                                    <span class="text-fg-dim" x-show="entry.status === 'cancelled'">{{ __('Cancelled') }}</span>

                                    <button
                                        type="button"
                                        class="cursor-pointer text-fg-faint hover:text-fg"
                                        x-show="entry.status === 'queued' && ! running"
                                        x-on:click="remove(entry)"
                                        aria-label="{{ __('Remove') }}"
                                    >
                                        <flux:icon.x-mark class="size-3.5" />
                                    </button>
                                </span>
                            </div>

                            <div class="h-1 overflow-hidden rounded-full bg-line" x-show="entry.status === 'uploading'">
                                <div class="h-full bg-accent transition-[width]" x-bind:style="'width: ' + percent(entry) + '%'"></div>
                            </div>

                            <p class="text-xs text-danger" x-show="entry.status === 'failed'" x-text="entry.message"></p>
                        </li>
                    </template>
                </ul>
            </template>

            <div class="flex items-center justify-between gap-2">
                <span class="font-mono text-xs text-fg-faint" x-show="running">{{ __('Keep this tab open until it finishes.') }}</span>
                <span x-show="! running"></span>

                <div class="flex items-center gap-2">
                    <flux:button variant="ghost" x-show="settled && ! running" x-on:click="clearSettled()">{{ __('Clear') }}</flux:button>
                    <flux:button variant="danger" x-show="running" x-on:click="stop()">{{ __('Stop') }}</flux:button>
                    <flux:button variant="primary" icon="arrow-up-tray" x-show="! running" x-bind:disabled="queued === 0" x-on:click="start()">
                        {{ __('Upload') }}
                    </flux:button>

                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Close') }}</flux:button>
                    </flux:modal.close>
                </div>
            </div>
        </div>
    </flux:modal>
</div>
