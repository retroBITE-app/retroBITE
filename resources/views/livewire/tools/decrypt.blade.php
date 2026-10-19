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

    /** @var list<int> file ids, in any state but Decrypting */
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
        $files = $keys->images($this->console)
            ->with(['game', 'meta'])
            ->orderBy('path')
            ->get();
        $decrypting = $keys->decrypting($files->modelKeys());

        return $files->map(function (GameFile $file) use ($keys, $decrypting): array {
            return ['file' => $file, 'state' => $keys->state($file, in_array($file->id, $decrypting, true))];
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
     * The shown images that can be picked, by id: all but those being decrypted.
     *
     * @return list<int>
     */
    #[Computed]
    public function pickable(): array
    {
        return $this->idsOf($this->shown, function (DecryptState $state): bool {
            return $state !== DecryptState::Decrypting;
        });
    }

    /**
     * The picks that can be decrypted now: those whose key fits.
     *
     * @return list<int>
     */
    #[Computed]
    public function pickedReady(): array
    {
        return array_values(array_intersect($this->picked, $this->idsOf($this->shown, function (DecryptState $state): bool {
            return $state === DecryptState::Ready;
        })));
    }

    /**
     * The images a Check all reads: none read yet, read without an answer, or with a key that does not fit.
     *
     * @return list<int>
     */
    #[Computed]
    public function unsettled(): array
    {
        return $this->idsOf($this->images, function (DecryptState $state): bool {
            return in_array($state, [DecryptState::Unchecked, DecryptState::Unknown, DecryptState::WrongKey], true);
        });
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

    /** A pick the search hides is dropped, so a bulk action never acts on what is not on screen. */
    public function updatedSearch(): void
    {
        $this->picked = array_values(array_intersect($this->picked, $this->pickable));
    }

    /** Pick or unpick one image, in any state but one already being decrypted. */
    public function toggle(int $id): void
    {
        if (! in_array($id, $this->pickable, true)) {
            return;
        }

        $this->picked = in_array($id, $this->picked, true)
            ? array_values(array_diff($this->picked, [$id]))
            : [...$this->picked, $id];
    }

    /** Pick every image shown, or none when they all are. */
    public function toggleAll(): void
    {
        $this->picked = count($this->picked) === count($this->pickable) ? [] : $this->pickable;
    }

    /** Read one image now, and say what it found: the answer comes in the same click. */
    public function check(int $id, DiscKeys $keys): void
    {
        $file = Arr::get($this->images->first(function (array $image) use ($id): bool {
            return Arr::get($image, 'file')->id === $id;
        }) ?? [], 'file');

        if (! $file instanceof GameFile) {
            return;
        }

        ['variant' => $variant, 'text' => $text] = $keys->check($file)->afterCheck();
        Flux::toast(variant: $variant, heading: $file->game->title, text: $text);

        $this->refreshImages();
    }

    /** Queue a check of every image not settled yet; the list follows as each one lands. */
    public function checkAll(): void
    {
        $this->queueChecks($this->unsettled);
    }

    /** Queue a check of every picked image. */
    public function checkPicked(): void
    {
        $this->queueChecks($this->picked);
        $this->picked = [];
    }

    /** Ask before decrypting the picks whose key fits; the others are left picked. */
    public function decryptPicked(): void
    {
        if ($this->pickedReady === []) {
            return;
        }

        $this->dispatch('decrypt-confirm', fileIds: $this->pickedReady);
    }

    /**
     * Queue one check per file on the toolbox queue, and say how many.
     *
     * @param  list<int>  $ids
     */
    private function queueChecks(array $ids): void
    {
        $count = InspectGameFile::queueFor($ids);

        Flux::toast(text: trans_choice(':count image queued for a check.|:count images queued for a check.', $count, ['count' => $count]));
    }

    /**
     * The file ids of images in a state the test accepts.
     *
     * @param  Collection<int, array{file: GameFile, state: DecryptState}>  $images
     * @param  callable(DecryptState): bool  $accepts
     * @return list<int>
     */
    private function idsOf(Collection $images, callable $accepts): array
    {
        return $images
            ->filter(function (array $image) use ($accepts): bool {
                return $accepts(Arr::get($image, 'state'));
            })
            ->map(function (array $image): int {
                return Arr::get($image, 'file')->id;
            })
            ->values()
            ->all();
    }

    /** The confirm modal queued the picks: start the list afresh. */
    #[On('decrypt-queued')]
    public function queued(): void
    {
        $this->picked = [];
        $this->refreshImages();
    }

    /** A key saved, a check landed, a decryption done: the states have moved. */
    #[On('disc-key-saved')]
    public function refreshImages(): void
    {
        unset($this->images, $this->counts, $this->shown, $this->pickable, $this->pickedReady, $this->unsettled);
        $this->picked = array_values(array_intersect($this->picked, $this->pickable));
    }
}; ?>

<x-live-system :signal="['conversion', 'inspected']" call="refreshImages" class="flex flex-col gap-6">
    <x-page-header
        :kicker="__('Tools')"
        :title="__('Decrypt')"
        :description="__('Some images are encrypted and will not start without their disc key. Here you can add the keys or decrypt the images.')"
        class="mb-0!"
    >
        <x-slot:actions>
            <livewire:conversion.tool-paths :only="Tools::onPage(Converter::PAGE_DECRYPT)" />
        </x-slot:actions>
    </x-page-header>

    @if ($this->consoles->isEmpty())
        <x-empty-state
            :title="__('No console with encrypted discs is in the library yet.')"
            :hint="__('Add one on the Consoles page — PlayStation 3, for one — and its disc images show up here.')"
        />
    @else
        <x-console-tabs :consoles="$this->consoles" :selected="$consoleKey" />

        @if ($this->missingTool !== null)
            <x-notice tone="warn">
                {{ __(':tool is not installed, so nothing can be decrypted. Keys can still be added.', ['tool' => $this->missingTool]) }}
            </x-notice>
        @endif

        <x-panel as="section" class="flex min-w-0 flex-col">
            {{-- One chip per state, with how many are in it. --}}
            <x-slot:header class="gap-1.5! px-3.5! py-3!">
                @foreach ($this->chips as $value => $label)
                    @php($count = $value === '' ? $this->images->count() : (int) Arr::get($this->counts, $value, 0))

                    <x-chip
                        :active="$show === $value"
                        :count="$count"
                        wire:key="chip-{{ $value ?: 'all' }}"
                        wire:click="filter({{ Js::from($value) }})"
                    >{{ $label }}</x-chip>
                @endforeach
            </x-slot:header>

            @if ($this->unsettled !== [])
                <x-slot:actions>
                    <flux:button size="xs" variant="ghost" icon="magnifying-glass" wire:click="checkAll" :title="__('Reads every image not settled yet: not checked, unknown, or with a key that does not fit.')">
                        {{ __('Check all (:count)', ['count' => count($this->unsettled)]) }}
                    </flux:button>
                </x-slot:actions>
            @endif

            <x-panel.header class="px-3.5! py-3!">
                <x-pick-all
                    :label="__('Pick every image shown')"
                    :state="match (true) {
                        $picked === [] => 'off',
                        count($picked) === count($this->pickable) => 'on',
                        default => 'some',
                    }"
                    wire:click="toggleAll"
                    :disabled="$this->pickable === []"
                />

                <x-search-field wire:model.live.debounce.300ms="search" :placeholder="__('Search games, files or title IDs')" class="min-w-0 flex-1" />

                <flux:button size="sm" icon="magnifying-glass" wire:click="checkPicked" :disabled="$picked === []">
                    {{ $picked === [] ? __('Check') : __('Check :count', ['count' => count($picked)]) }}
                </flux:button>

                {{-- Only the picks whose key fits: the rest stay picked, to check or key. --}}
                <flux:button variant="primary" size="sm" icon="lock-open" wire:click="decryptPicked" :disabled="$this->pickedReady === [] || $this->missingTool !== null">
                    {{ $this->pickedReady === [] ? __('Decrypt') : __('Decrypt :count', ['count' => count($this->pickedReady)]) }}
                </flux:button>
            </x-panel.header>

            <ul class="max-h-[32rem] divide-y divide-line overflow-y-auto">
                @forelse ($this->shown as ['file' => $file, 'state' => $state])
                    @php($pickable = $state !== DecryptState::Decrypting)
                    @php($isPicked = in_array($file->id, $picked, true))

                    <li wire:key="image-{{ $file->id }}" @class([
                        'flex items-center gap-3 px-3.5 py-2.5 transition-colors',
                        'bg-accent-tint/10' => $isPicked,
                    ])>
                        {{-- Any state can be picked, to check; Decrypt then takes
                             only the picks whose key fits. One being decrypted
                             has nothing left to do. --}}
                        <button
                            type="button"
                            @if ($pickable)
                                wire:click="toggle({{ $file->id }})"
                                aria-pressed="{{ $isPicked ? 'true' : 'false' }}"
                                aria-label="{{ __('Pick :file', ['file' => $file->filename]) }}"
                            @else
                                disabled
                                title="{{ $state->hint() }}"
                            @endif
                            @class([
                                'shrink-0',
                                'cursor-pointer' => $pickable,
                                'cursor-not-allowed opacity-30' => ! $pickable,
                            ])
                        >
                            <x-check :state="$isPicked ? 'on' : 'off'" />
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
                            @if ($state->dashed())
                                <flux:button size="xs" variant="ghost" wire:click="check({{ $file->id }})">{{ __('Check') }}</flux:button>
                            @elseif ($state->wantsKey() || $state === DecryptState::Ready)
                                <flux:button size="xs" variant="ghost" icon="key" x-on:click="$dispatch('disc-key', { fileId: {{ $file->id }} })">
                                    {{ match ($state) {
                                        DecryptState::Ready => __('Change key'),
                                        DecryptState::WrongKey => __('Replace key'),
                                        default => __('Add key'),
                                    } }}
                                </flux:button>
                            @endif
                        </div>
                    </li>
                @empty
                    <li>
                        <x-empty-state variant="inline">
                            {{ $this->images->isEmpty()
                                ? __('No :console disc images in the library. Scan the folder if files were added.', ['console' => $this->console?->name])
                                : __('No image matches.') }}
                        </x-empty-state>
                    </li>
                @endforelse
            </ul>
        </x-panel>
    @endif

    @if ($this->converter !== null)
        <livewire:conversion.queue :converter="$this->converter->key()" wire:key="queue-{{ $consoleKey }}" />
    @endif

    <livewire:decrypt.key-modal />

    <livewire:decrypt.confirm-modal />
</x-live-system>
