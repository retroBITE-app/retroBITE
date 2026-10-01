<?php

use App\Enums\TransferFailure;
use App\Jobs\DiscoverShares;
use App\Jobs\ListShares;
use App\Models\Destination;
use App\Support\TransferRegions;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Destinations')] class extends Component
{
    /** Seconds before a search or a listing is given up on. */
    public const WAIT_SECONDS = 30;

    public string $name = '';

    public string $host = '';

    public string $share = '';

    public string $folder = '';

    public string $username = '';

    /**
     * Left blank when editing a destination that has one stored: the stored
     * password never goes to the browser, and blank means leave it alone.
     */
    public string $password = '';

    /** The destination the form is editing, or null for a new one. */
    public ?int $editing = null;

    /** The network search in flight, if any: its cache token and when it began. */
    public ?string $searchToken = null;

    public ?int $searchSince = null;

    /** @var list<array{name: string, address: string, via: string}>|null null until a search has answered */
    public ?array $found = null;

    /** @var list<string> the networks the last search scanned, e.g. 192.168.1.0/24 */
    public array $scanned = [];

    /** The share listing in flight, if any. */
    public ?string $listToken = null;

    public ?int $listSince = null;

    /** @var array{host: string, address: ?string, shares: list<string>, failure: ?string}|null */
    public ?array $listing = null;

    /** @var list<string> the order Send to tries regions in, saved as it changes */
    public array $regions = [];

    public function mount(): void
    {
        $this->regions = TransferRegions::order();
    }

    /**
     * Kept as it is sorted. Only regions the app knows, and never none: an
     * empty order is the artwork's, which would look like nothing was removed.
     */
    public function updatedRegions(): void
    {
        $known = array_keys(TransferRegions::besides([]));
        $regions = array_values(array_unique(array_filter(
            array_map(strval(...), $this->regions),
            fn (string $code): bool => in_array($code, $known, true),
        )));

        if ($regions === []) {
            $this->regions = TransferRegions::order();

            return;
        }

        TransferRegions::remember($regions);
        $this->regions = TransferRegions::order();
    }

    /** @return Collection<int, Destination> */
    #[Computed]
    public function destinations(): Collection
    {
        return Destination::query()->orderBy('name')->get();
    }

    /** Look for machines sharing files. Queued: no request waits on the network. */
    public function search(): void
    {
        $this->searchToken = (string) Str::uuid();
        $this->searchSince = now()->timestamp;
        $this->found = null;

        // The address this page was opened on, and the browser's: behind
        // Docker's bridge the container cannot see which network is the LAN,
        // and either of these may be on it.
        dispatch(new DiscoverShares($this->searchToken, array_values(array_filter([request()->getHost(), request()->ip()]))));
    }

    /** Run on the discovery signal, and once at the timeout. */
    public function checkSearch(): void
    {
        if ($this->searchToken === null) {
            return;
        }

        $answer = Cache::get(DiscoverShares::cacheKey($this->searchToken));

        if (is_array($answer)) {
            $this->found = array_values($answer['hosts'] ?? []);
            $this->scanned = array_values($answer['subnets'] ?? []);
            $this->searchToken = null;
        } elseif (now()->timestamp - (int) $this->searchSince >= self::WAIT_SECONDS) {
            $this->found = [];
            $this->scanned = [];
            $this->searchToken = null;
        }
    }

    /**
     * Take a found machine and ask it for its shares.
     *
     * The name when DNS answered for it, because a name survives the router
     * handing out a new address; otherwise the address, because a name only
     * mDNS or NetBIOS knows cannot be resolved from behind Docker's bridge.
     */
    public function pick(int $index): void
    {
        $host = $this->found[$index] ?? null;

        if ($host === null) {
            return;
        }

        $this->host = $host['via'] === 'dns' ? $host['name'] : $host['address'];

        if ($this->name === '') {
            // NetBIOS names arrive in capitals: BATOCERA as Batocera.
            $this->name = Str::title(strtolower($host['name']));
        }

        $this->listShares();
    }

    public function listShares(): void
    {
        $this->validate(['host' => ['required', 'string', 'max:255']]);

        $this->listToken = (string) Str::uuid();
        $this->listSince = now()->timestamp;
        $this->listing = null;

        dispatch(new ListShares(
            $this->listToken,
            trim($this->host),
            $this->username !== '' ? $this->username : null,
            $this->password !== '' ? $this->password : null,
        ));
    }

    public function checkListing(): void
    {
        if ($this->listToken === null) {
            return;
        }

        $answer = Cache::get(ListShares::cacheKey($this->listToken));

        if (is_array($answer)) {
            $this->listing = $answer;
            $this->listToken = null;

            // One share, and it is the only thing to choose: choose it.
            if (count($answer['shares']) === 1 && $this->share === '') {
                $this->share = $answer['shares'][0];
            }
        } elseif (now()->timestamp - (int) $this->listSince >= self::WAIT_SECONDS) {
            $this->listing = ['host' => $this->host, 'address' => null, 'shares' => [], 'failure' => TransferFailure::Unreachable->value];
            $this->listToken = null;
        }
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-:]+$/'],
            'share' => ['required', 'string', 'max:255', 'not_regex:/[\/\\\\]/'],
            // Not the roms folder itself: every transfer target adds roms/ to
            // (or ROMs/) to every path, so pointing here at it lands games in
            // roms/roms/, where no front-end looks.
            'folder' => ['nullable', 'string', 'max:255', 'not_regex:/(^|[\/\\\\])\.\.?([\/\\\\]|$)/', 'not_regex:/(^|[\/\\\\])roms[\/\\\\]*$/i'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
        ], [
            'host.regex' => __('A name or an address, without \\\\ or slashes.'),
            'share.not_regex' => __('Just the share\'s name; a folder inside it goes in Folder.'),
            'folder.not_regex' => str_ends_with(strtolower(rtrim(str_replace('\\', '/', $this->folder), '/')), 'roms')
                ? __('Send to adds roms/ itself. Leave this empty for Batocera\'s share, or name the folder that holds roms/.')
                : __('A folder inside the share, without . or .. in it.'),
        ]);

        $attributes = [
            'name' => trim($this->name),
            'host' => trim($this->host),
            'share' => trim($this->share),
            'folder' => trim(str_replace('\\', '/', $this->folder), '/'),
            'username' => $this->username !== '' ? $this->username : null,
        ];

        // A new password when one was typed; otherwise the stored one stays,
        // unless the username went too — a guest share has no password.
        if ($this->password !== '' || $attributes['username'] === null) {
            $attributes['password'] = $this->password !== '' ? $this->password : null;
        }

        $destination = $this->editing !== null ? Destination::query()->find($this->editing) : null;
        $destination !== null ? $destination->update($attributes) : Destination::query()->create($attributes);

        $this->cancelEdit();
        unset($this->destinations);

        Flux::toast(variant: 'success', text: __('Destination saved.'));
    }

    /** Fill the form with a saved destination, to change it. */
    public function edit(int $id): void
    {
        $destination = Destination::query()->find($id);

        if ($destination === null) {
            return;
        }

        $this->editing = $destination->id;
        $this->name = $destination->name;
        $this->host = $destination->host;
        $this->share = $destination->share;
        $this->folder = $destination->folder;
        $this->username = (string) $destination->username;
        $this->password = '';
        $this->listing = null;
        $this->resetValidation();
    }

    public function cancelEdit(): void
    {
        $this->reset('name', 'host', 'share', 'folder', 'username', 'password', 'listing', 'found', 'scanned', 'editing');
        $this->resetValidation();
    }

    public function remove(int $id): void
    {
        if ($this->editing === $id) {
            $this->cancelEdit();
        }

        Destination::query()->whereKey($id)->delete();
        unset($this->destinations);

        Flux::toast(variant: 'success', text: __('Destination removed.'));
    }

    /** The sentence for a listing that failed. */
    public function listingProblem(): ?string
    {
        $failure = TransferFailure::tryFrom((string) ($this->listing['failure'] ?? ''));

        return match ($failure) {
            null => null,
            TransferFailure::Unreachable => __('Nothing answered at :host.', ['host' => $this->listing['host'] ?? $this->host]),
            TransferFailure::Denied => __('That machine wants a username and password before it lists its shares.'),
            default => $failure->label(),
        };
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Destinations')" :subheading="__('Network shares games can be sent to')" wide>
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
            <div class="flex flex-col gap-6 lg:col-span-7">
                <div class="rounded-xl border border-line bg-surface p-5">
                    <div class="mb-2 flex items-center gap-2.5">
                        <flux:icon.server-stack class="size-[17px] text-accent" />
                        <p class="flex-1 text-sm text-fg-bright">{{ $editing !== null ? __('Edit :name', ['name' => $name]) : __('Add a share') }}</p>
                    </div>

                    <p class="mb-6 text-sm text-fg-soft">
                        {{ __('A shared folder on another machine — a Batocera box, a NAS — that Send to can copy games onto. Four steps, top to bottom.') }}
                    </p>

                    <form wire:submit="save" class="flex flex-col gap-7">
                        {{-- Step one: find the machine, so nobody has to know its address. --}}
                        <x-settings.step number="1" :title="__('Find the machine')"
                                         :hint="__('Search the network for it, or type its name or address.')">
                            <flux:button size="sm" variant="filled" icon="magnifying-glass" type="button" wire:click="search" x-bind:disabled="$wire.searchToken !== null">
                                {{ __('Search the network') }}
                            </flux:button>

                            @if ($searchToken !== null)
                                <div
                                    wire:key="search-{{ $searchToken }}"
                                    x-data="{
                                        stop: null, timer: null,
                                        init() {
                                            this.stop = live.system('discovery', () => $wire.checkSearch());
                                            this.timer = setTimeout(() => $wire.checkSearch(), {{ ($this::WAIT_SECONDS + 1) * 1000 }});
                                        },
                                        destroy() { this.stop?.(); clearTimeout(this.timer); },
                                    }"
                                    class="mt-3 flex items-center gap-2 text-sm text-fg-soft"
                                >
                                    <flux:icon.loading class="size-4" />
                                    {{ __('Asking the network who shares files…') }}
                                </div>
                            @elseif ($found !== null)
                                @if ($found === [])
                                    <p class="mt-3 text-sm text-fg-faint">
                                        {{ __('Nothing answered. Type the machine\'s name or address below instead — for a Batocera box, try batocera.') }}
                                    </p>
                                    @if ($scanned === [])
                                        {{-- Behind Docker's bridge the container cannot
                                             see which network is the LAN, so it had
                                             none to scan. Said, since it is one line
                                             in .env to fix. --}}
                                        <p class="mt-2 text-xs text-fg-faint">
                                            {{ __('retroBite could not tell which network your LAN is, so it only asked by name. Set HOST_IP in .env to this machine\'s LAN address (or TRANSFER_DISCOVERY_SUBNETS to the network) and it will search the whole network.') }}
                                        </p>
                                    @endif
                                @else
                                    <p class="mt-3 text-xs text-fg-faint">{{ __('Pick one to fill in its address and list its shares.') }}</p>
                                    <ul class="mt-1.5 flex flex-col gap-1.5">
                                        @foreach ($found as $index => $machine)
                                            <li wire:key="found-{{ $machine['address'] }}">
                                                <button
                                                    type="button"
                                                    wire:click="pick({{ $index }})"
                                                    class="flex w-full cursor-pointer items-center gap-3 rounded-lg border border-line-input bg-sunken px-3 py-2 text-left text-sm transition-colors hover:border-accent/40"
                                                >
                                                    <flux:icon.computer-desktop class="size-4 text-fg-muted" />
                                                    <span class="font-mono text-fg-bright">{{ $machine['name'] }}</span>
                                                    @if ($machine['name'] !== $machine['address'])
                                                        <span class="ml-auto font-mono text-xs text-fg-faint">{{ $machine['address'] }}</span>
                                                    @endif
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            @endif

                            <div class="mt-4">
                                <flux:field>
                                    <div class="flex items-center gap-2">
                                        <flux:label>{{ __('Machine') }}</flux:label>
                                        <x-info :text="__('A name like batocera, or an address like 192.168.1.20.')" />
                                    </div>
                                    <flux:input wire:model="host" placeholder="batocera" />
                                    <flux:error name="host" />
                                </flux:field>
                            </div>
                        </x-settings.step>

                        {{-- Step two: only when the share is not open to guests. --}}
                        <x-settings.step number="2" :title="__('Sign in, if it asks')"
                                         :hint="__('Batocera shares to guests: leave both empty. A NAS usually wants its own account.')">
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <flux:field>
                                    <div class="flex items-center gap-2">
                                        <flux:label>{{ __('Username') }}</flux:label>
                                        <x-info :text="__('Leave empty for a guest share.')" />
                                    </div>
                                    <flux:input wire:model="username" :placeholder="__('Optional')" autocomplete="off" />
                                    <flux:error name="username" />
                                </flux:field>
                                <flux:field>
                                    <div class="flex items-center gap-2">
                                        <flux:label>{{ __('Password') }}</flux:label>
                                        <x-info :text="__('Kept encrypted, and never sent back to the browser.')" />
                                    </div>
                                    <flux:input wire:model="password" type="password" autocomplete="new-password"
                                                :placeholder="$editing !== null ? __('Unchanged unless typed') : __('Optional')" />
                                    <flux:error name="password" />
                                </flux:field>
                            </div>
                        </x-settings.step>

                        {{-- Step three: ask the machine for its shares, and pick one. --}}
                        <x-settings.step number="3" :title="__('Pick the share')"
                                         :hint="__('Ask the machine which shares it has, then choose one. Batocera\'s is called share.')">
                            <flux:button size="sm" variant="filled" icon="folder-open" type="button" wire:click="listShares" x-bind:disabled="$wire.listToken !== null">
                                {{ __('List its shares') }}
                            </flux:button>

                            @if ($listToken !== null)
                                <div
                                    wire:key="list-{{ $listToken }}"
                                    x-data="{
                                        stop: null, timer: null,
                                        init() {
                                            this.stop = live.system('discovery', () => $wire.checkListing());
                                            this.timer = setTimeout(() => $wire.checkListing(), {{ ($this::WAIT_SECONDS + 1) * 1000 }});
                                        },
                                        destroy() { this.stop?.(); clearTimeout(this.timer); },
                                    }"
                                    class="mt-3 flex items-center gap-2 text-sm text-fg-soft"
                                >
                                    <flux:icon.loading class="size-4" />
                                    {{ __('Asking :host for its shares…', ['host' => $host]) }}
                                </div>
                            @elseif ($this->listingProblem() !== null)
                                <p class="mt-3 text-sm text-danger">{{ $this->listingProblem() }}</p>
                            @elseif ($listing !== null && $listing['shares'] === [])
                                <p class="mt-3 text-sm text-fg-faint">{{ __('It answered, but shares nothing this account can see.') }}</p>
                            @endif

                            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <flux:field>
                                    <div class="flex items-center gap-2">
                                        <flux:label>{{ __('Share') }}</flux:label>
                                        <x-info :text="__('Just the share\'s name, without slashes. A folder inside it goes in Folder.')" />
                                    </div>
                                    @if (($listing['shares'] ?? []) !== [])
                                        <flux:select wire:model="share" :placeholder="__('Choose a share')">
                                            @foreach ($listing['shares'] as $shareName)
                                                <flux:select.option value="{{ $shareName }}">{{ $shareName }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                    @else
                                        <flux:input wire:model="share" placeholder="share" />
                                    @endif
                                    <flux:error name="share" />
                                </flux:field>

                                <flux:field>
                                    <div class="flex items-center gap-2">
                                        <flux:label>{{ __('Folder') }}</flux:label>
                                        <x-info :text="__('Empty for Batocera. Elsewhere, the folder that holds roms/ — not roms/ itself.')" />
                                    </div>
                                    <flux:input wire:model="folder" :placeholder="__('Optional — the share\'s root')" />
                                    <flux:error name="folder" />
                                </flux:field>
                            </div>
                        </x-settings.step>

                        {{-- Step four: what Send to lists it as. --}}
                        <x-settings.step number="4" :title="__('Name it')"
                                         :hint="__('What Send to lists it as. Filled in for you when you pick a machine.')">
                            <flux:input wire:model="name" :label="__('Name')" placeholder="Living room Batocera" />

                            <div class="mt-5 flex gap-2">
                                <flux:button variant="primary" type="submit">{{ $editing !== null ? __('Save changes') : __('Save destination') }}</flux:button>
                                @if ($editing !== null)
                                    <flux:button variant="ghost" type="button" wire:click="cancelEdit">{{ __('Cancel') }}</flux:button>
                                @endif
                            </div>
                        </x-settings.step>
                    </form>
                </div>
            </div>

            <div class="flex flex-col gap-6 lg:col-span-5">
                <div class="rounded-xl border border-line bg-surface p-5">
                    <p class="kicker mb-3 text-fg-faint">{{ __('Saved') }}</p>

                    @if ($this->destinations->isEmpty())
                        <p class="text-sm text-fg-faint">{{ __('None yet. Add one on the left and it shows up in Send to.') }}</p>
                    @else
                        <ul class="flex flex-col gap-2.5">
                            @foreach ($this->destinations as $destination)
                                <li wire:key="destination-{{ $destination->id }}" class="flex items-center gap-3 border-b border-raised pb-2.5 last:border-0 last:pb-0">
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm text-fg-bright">{{ $destination->name }}</p>
                                        <p class="truncate font-mono text-xs text-fg-faint">{{ $destination->address() }}</p>
                                    </div>
                                    <flux:button size="xs" variant="ghost" icon="pencil-square" type="button"
                                                 wire:click="edit({{ $destination->id }})"
                                                 :aria-label="__('Edit :name', ['name' => $destination->name])" />
                                    <flux:button size="xs" variant="ghost" icon="trash" type="button"
                                                 wire:click="remove({{ $destination->id }})"
                                                 wire:confirm="{{ __('Remove :name? Nothing on the share is touched.', ['name' => $destination->name]) }}"
                                                 :aria-label="__('Remove :name', ['name' => $destination->name])" />
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                {{-- Which version goes, for a game that holds several: the
                     library's order, which a console can have its own of
                     (Settings → Consoles) and Send to can lead with one. --}}
                <div class="rounded-xl border border-line bg-surface p-5">
                    <p class="kicker mb-1 text-fg-faint">{{ __('Region order') }}</p>
                    <p class="mb-4 text-sm text-fg-soft">
                        {{ __('A game held in several regions sends one version: the first region here it has, else any other. A console can have its own order under Settings → Consoles, and Send to can sort one for a single send.') }}
                    </p>

                    <x-region-order wire:model.live="regions" keep-one />
                </div>

                {{-- What people ask before they add one. --}}
                <div class="rounded-xl border border-line bg-surface p-5">
                    <p class="kicker mb-3 text-fg-faint">{{ __('Good to know') }}</p>

                    <ul class="flex flex-col gap-3 text-sm text-fg-soft">
                        <li class="flex gap-2.5">
                            <flux:icon.document-duplicate variant="micro" class="mt-0.5 size-4 shrink-0 text-fg-muted" />
                            <span>{{ __('Send to copies. Your library keeps every file.') }}</span>
                        </li>
                        <li class="flex gap-2.5">
                            <flux:icon.computer-desktop variant="micro" class="mt-0.5 size-4 shrink-0 text-fg-muted" />
                            <span>{{ __('A USB drive on this computer needs no setting up: Send to always offers it.') }}</span>
                        </li>
                        <li class="flex gap-2.5">
                            <flux:icon.folder variant="micro" class="mt-0.5 size-4 shrink-0 text-fg-muted" />
                            <span>{{ __('Send to adds roms/ to every path itself, so point Folder above it, never at it.') }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </x-settings.layout>
</section>
