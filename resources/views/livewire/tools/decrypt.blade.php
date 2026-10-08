<?php

use App\Concerns\PicksConsoleTab;
use App\Conversion\Converter;
use App\Conversion\Converters;
use App\Conversion\Tools;
use App\Decryption\DiscKeys;
use App\Enums\DecryptState;
use App\Jobs\InspectGameFile;
use App\Models\GameFile;
use App\Support\Console;
use Flux\Flux;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Tools → Decrypt: a console's encrypted disc images, their keys, and
 * decrypting them for good. One tab per console in the library that has a
 * converter on this page (Converter::page() === 'decrypt'), as Tools →
 * Conversion has one per console with any — PS3 today.
 *
 * A Redump PS3 dump is encrypted and will not start without its key. With the
 * key beside it (Add key) it already plays over ps3netsrv; Decrypt makes that
 * permanent — ps3dec writes the decrypted image in the encrypted one's place
 * and the key goes, so it plays anywhere. The work is a conversion
 * (App\Decryption\Ps3Decrypt), so it queues, reports and cancels
 * the way Tools → Conversion's do, and the queue under the list is that one,
 * held to the tab's converter.
 */
new #[Title('Decrypt')] class extends Component
{
    use PicksConsoleTab;

    /** One state's images, by its value; '' for all of them. */
    #[Url(as: 'show', except: '')]
    public string $show = '';

    #[Url(as: 'search', except: '')]
    public string $search = '';

    /** @var list<int> file ids, Ready ones only */
    public array $picked = [];

    /** A tab for every console some converter on this page decrypts. */
    protected function offersConsole(Console $console): bool
    {
        return Converters::onPage($console, Converter::PAGE_DECRYPT)->isNotEmpty();
    }

    /** The tab's console. */
    #[Computed]
    public function console(): ?Console
    {
        return $this->consoles->get($this->consoleKey);
    }

    /** What decrypts the tab's console's images. */
    #[Computed]
    public function converter(): ?Converter
    {
        return $this->console !== null ? Converters::onPage($this->console, Converter::PAGE_DECRYPT)->first() : null;
    }

    /** The tool that is not installed, if one is not: nothing can be decrypted without it. */
    #[Computed]
    public function missingTool(): ?string
    {
        return $this->converter !== null ? Tools::missing($this->converter) : null;
    }

    /**
     * Every image on the tab's console the converter reads, on disk, with where it stands.
     *
     * @return Collection<int, array{file: GameFile, state: DecryptState}>
     */
    #[Computed]
    public function images(): Collection
    {
        if ($this->console === null || $this->converter === null) {
            return collect();
        }

        $keys = app(DiscKeys::class);

        return GameFile::query()
            ->present()
            ->onConsole($this->console->key)
            ->whereIn('extension', $this->converter->from())
            ->with(['game', 'meta'])
            ->orderBy('path')
            ->get()
            ->map(function (GameFile $file) use ($keys): array {
                return ['file' => $file, 'state' => $keys->state($file)];
            });
    }

    /**
     * The filter chips: All, then every state, by value.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function chips(): array
    {
        return [
            '' => __('All'),
            ...collect(DecryptState::cases())
                ->mapWithKeys(function (DecryptState $state): array {
                    return [$state->value => $state->label()];
                })
                ->all(),
        ];
    }

    /**
     * How many images are in each state, by value.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        return $this->images
            ->countBy(function (array $image): string {
                return Arr::get($image, 'state')->value;
            })
            ->all();
    }

    /**
     * The images the filter and the search leave.
     *
     * @return Collection<int, array{file: GameFile, state: DecryptState}>
     */
    #[Computed]
    public function shown(): Collection
    {
        $needle = Str::lower(trim($this->search));

        return $this->images
            ->filter(function (array $image) use ($needle): bool {
                ['file' => $file, 'state' => $state] = $image;

                if ($this->show !== '' && $state->value !== $this->show) {
                    return false;
                }

                return $needle === ''
                    || Str::contains(Str::lower($file->filename), $needle)
                    || Str::contains(Str::lower((string) $file->game->title), $needle)
                    || Str::contains(Str::lower((string) $file->meta?->license_id), $needle);
            })
            ->values();
    }

    /**
     * The shown images that can be decrypted now, by id.
     *
     * @return list<int>
     */
    #[Computed]
    public function ready(): array
    {
        return $this->shown
            ->filter(function (array $image): bool {
                return Arr::get($image, 'state') === DecryptState::Ready;
            })
            ->map(function (array $image): int {
                return Arr::get($image, 'file')->id;
            })
            ->values()
            ->all();
    }

    /** Another console: its own filter, search and picks. */
    protected function consoleSelected(): void
    {
        $this->show = '';
        $this->search = '';
        $this->picked = [];

        unset($this->console, $this->converter, $this->missingTool);
        $this->refreshImages();
    }

    /** Show one state's images, or all of them for anything that is not a state. */
    public function filter(string $state): void
    {
        $this->show = DecryptState::tryFrom($state)?->value ?? '';
        $this->picked = [];
    }

    /** A pick the search hides is dropped, so Decrypt never acts on what is not on screen. */
    public function updatedSearch(): void
    {
        $this->picked = array_values(array_intersect($this->picked, $this->ready));
    }

    /** Pick or unpick one image; only one with its key can be picked. */
    public function toggle(int $id): void
    {
        if (! in_array($id, $this->ready, true)) {
            return;
        }

        $this->picked = in_array($id, $this->picked, true)
            ? array_values(array_diff($this->picked, [$id]))
            : [...$this->picked, $id];
    }

    /** Pick every ready image shown, or none when they all are. */
    public function toggleAll(): void
    {
        $this->picked = count($this->picked) === count($this->ready) ? [] : $this->ready;
    }

    /** Read one image now rather than waiting for the next scan. */
    public function check(int $id): void
    {
        if ($this->images->contains(function (array $image) use ($id): bool {
            return Arr::get($image, 'file')->id === $id;
        })) {
            (new InspectGameFile($id, force: true))->handle();
        }

        $this->refreshImages();
    }

    /** Read every image not read yet. A few sectors each, so done here. */
    public function checkAll(): void
    {
        $this->images
            ->filter(function (array $image): bool {
                return Arr::get($image, 'state') === DecryptState::Unchecked;
            })
            ->each(function (array $image): void {
                (new InspectGameFile(Arr::get($image, 'file')->id, force: true))->handle();
            });

        $this->refreshImages();
    }

    /**
     * Queue the picked images, one decryption each. Their keys go on record
     * first: decrypting deletes the .dkey, and the key is still worth having.
     */
    public function decrypt(DiscKeys $keys): void
    {
        ['queued' => $queued, 'skipped' => $skipped] = $keys->queueDecryption(
            GameFile::query()->whereKey(array_intersect($this->picked, $this->ready))->with('game')->get(),
        );

        $this->picked = [];
        $this->dispatch('conversion-queued');

        Flux::toast(
            variant: $queued === [] ? 'danger' : 'success',
            text: $skipped === 0
                ? trans_choice(':count image queued for decryption.|:count images queued for decryption.', count($queued), ['count' => count($queued)])
                : __(':queued queued. :skipped could not be decrypted now: check they still have their key.', ['queued' => count($queued), 'skipped' => $skipped]),
        );
    }

    /** A key saved, a decryption done: the states have moved. */
    #[On('disc-key-saved')]
    public function refreshImages(): void
    {
        unset($this->images, $this->counts, $this->shown, $this->ready);
        $this->picked = array_values(array_intersect($this->picked, $this->ready));
    }
}; ?>

<div
    class="flex flex-col gap-6"
    x-data="{
        stop: null,
        init() { this.stop = live.system('conversion', () => $wire.refreshImages()) },
        destroy() { this.stop?.() },
    }"
>
    <div>
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="kicker mb-1.5 text-fg-faint">{{ __('Tools') }}</p>
                <h1 class="text-display font-medium tracking-display text-fg-bright">{{ __('Decrypt') }}</h1>
            </div>

            <livewire:conversion.tool-paths :only="Tools::onPage(Converter::PAGE_DECRYPT)" />
        </div>
        <p class="mt-2 max-w-3xl text-sm text-fg-muted">
            {{ __('Some images are encrypted and will not start without their disc key. Here you can add the keys or decrypt the images.') }}
        </p>
    </div>

    @if ($this->consoles->isEmpty())
        <div class="rounded-xl border border-dashed border-line-input px-6 py-10 text-center">
            <p class="text-sm text-fg-soft">{{ __('No console with encrypted discs is in the library yet.') }}</p>
            <p class="mt-1 text-sm text-fg-faint">{{ __('Add one on the Consoles page — PlayStation 3, for one — and its disc images show up here.') }}</p>
        </div>
    @else
        <x-console-tabs :consoles="$this->consoles" :selected="$consoleKey" />

        @if ($this->missingTool !== null)
            <div class="rounded-xl border border-warn/50 bg-surface px-4 py-3 text-sm text-warn">
                {{ __(':tool is not installed, so nothing can be decrypted. Keys can still be added.', ['tool' => $this->missingTool]) }}
            </div>
        @endif

        <section class="flex min-w-0 flex-col rounded-xl border border-line bg-surface">
            {{-- One chip per state, with how many are in it. --}}
            <div class="flex flex-wrap items-center gap-1.5 border-b border-line px-3.5 py-3">
                @foreach ($this->chips as $value => $label)
                    @php($count = $value === '' ? $this->images->count() : (int) Arr::get($this->counts, $value, 0))

                    <button
                        type="button"
                        wire:key="chip-{{ $value ?: 'all' }}"
                        wire:click="filter(@js($value))"
                        @class([
                            'flex cursor-pointer items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs transition-colors',
                            'border-accent-tint/55 bg-accent-tint/10 text-accent' => $show === $value,
                            'border-line text-fg-muted hover:border-line-bright hover:text-fg-soft' => $show !== $value,
                        ])
                    >
                        {{ $label }}
                        <span class="font-mono text-fg-faint">{{ $count }}</span>
                    </button>
                @endforeach

                @if (Arr::get($this->counts, DecryptState::Unchecked->value, 0) > 0)
                    <flux:button size="xs" variant="ghost" icon="magnifying-glass" wire:click="checkAll" class="ml-auto">
                        {{ __('Check unread') }}
                    </flux:button>
                @endif
            </div>

            <div class="flex items-center gap-3 border-b border-line px-3.5 py-3">
                <button
                    type="button"
                    wire:click="toggleAll"
                    @disabled($this->ready === [])
                    aria-label="{{ __('Pick every image that is ready') }}"
                    title="{{ __('Pick every image that is ready') }}"
                    class="shrink-0 cursor-pointer disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <x-conversion.check :state="match (true) {
                        $picked === [] => 'off',
                        count($picked) === count($this->ready) => 'on',
                        default => 'some',
                    }" />
                </button>

                <x-search-field wire:model.live.debounce.300ms="search" :placeholder="__('Search games, files or title IDs')" class="min-w-0 flex-1" />

                <flux:button variant="primary" size="sm" icon="lock-open" wire:click="decrypt" :disabled="$picked === [] || $this->missingTool !== null">
                    {{ $picked === [] ? __('Decrypt') : __('Decrypt :count', ['count' => count($picked)]) }}
                </flux:button>
            </div>

            <ul class="max-h-[32rem] divide-y divide-line overflow-y-auto">
                @forelse ($this->shown as ['file' => $file, 'state' => $state])
                    @php($pickable = $state === DecryptState::Ready)
                    @php($isPicked = in_array($file->id, $picked, true))

                    <li wire:key="image-{{ $file->id }}" @class([
                        'flex items-center gap-3 px-3.5 py-2.5 transition-colors',
                        'bg-accent-tint/10' => $isPicked,
                    ])>
                        {{-- Only an image with its key can be decrypted. Without
                             one, the box asks for the key rather than sitting
                             there greyed out with no reason given. --}}
                        <button
                            type="button"
                            @if ($pickable)
                                wire:click="toggle({{ $file->id }})"
                                aria-pressed="{{ $isPicked ? 'true' : 'false' }}"
                                aria-label="{{ __('Pick :file', ['file' => $file->filename]) }}"
                            @elseif ($state === DecryptState::NeedsKey)
                                x-on:click="$dispatch('disc-key', { fileId: {{ $file->id }} })"
                                aria-label="{{ __('Add the key for :file', ['file' => $file->filename]) }}"
                                title="{{ __('Add its disc key first: it cannot be decrypted without one.') }}"
                            @else
                                disabled
                                title="{{ $state->hint() }}"
                            @endif
                            @class([
                                'shrink-0',
                                'cursor-pointer' => $pickable || $state === DecryptState::NeedsKey,
                                'opacity-40' => ! $pickable && $state === DecryptState::NeedsKey,
                                'cursor-not-allowed opacity-30' => ! $pickable && $state !== DecryptState::NeedsKey,
                            ])
                        >
                            <x-conversion.check :state="$isPicked ? 'on' : 'off'" />
                        </button>

                        <div class="min-w-0 flex-1">
                            <a href="{{ route('games.show', $file->game->routeParameters()) }}" wire:navigate class="block truncate text-sm text-fg hover:text-accent">
                                {{ $file->game->title }}
                            </a>
                            <p class="truncate font-mono text-xs text-fg-faint">{{ $file->filename }}</p>
                        </div>

                        @if ($file->meta?->license_id)
                            <span class="hidden shrink-0 font-mono text-xs text-fg-muted sm:inline">{{ $file->meta->license_id }}</span>
                        @endif

                        <span class="hidden w-16 shrink-0 text-right font-mono text-xs text-fg-faint sm:inline">{{ Number::fileSize((int) $file->size_bytes, 1) }}</span>

                        <x-decrypt.state :state="$state" />

                        <div class="flex w-24 shrink-0 justify-end">
                            @if ($state === DecryptState::Unchecked)
                                <flux:button size="xs" variant="ghost" wire:click="check({{ $file->id }})">{{ __('Check') }}</flux:button>
                            @elseif ($state !== DecryptState::Decrypted)
                                <flux:button size="xs" variant="ghost" icon="key" x-on:click="$dispatch('disc-key', { fileId: {{ $file->id }} })">
                                    {{ $state === DecryptState::Ready ? __('Change key') : __('Add key') }}
                                </flux:button>
                            @endif
                        </div>
                    </li>
                @empty
                    <li class="px-4 py-8 text-center text-sm text-fg-faint">
                        {{ $this->images->isEmpty()
                            ? __('No :console disc images in the library. Scan the folder if files were added.', ['console' => $this->console?->name])
                            : __('No image matches.') }}
                    </li>
                @endforelse
            </ul>
        </section>
    @endif

    @if ($this->converter !== null)
        <livewire:conversion.queue :converter="$this->converter->key()" wire:key="queue-{{ $consoleKey }}" />
    @endif

    <livewire:decrypt.key-modal />
</div>
