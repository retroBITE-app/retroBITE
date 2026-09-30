<?php

use App\Enums\TransferFailure;
use App\Jobs\DiscoverShares;
use App\Jobs\ListShares;
use App\Models\Destination;
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
            'share.not_regex' => __('Just the share\'s name; a folder inside it goes below.'),
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
                    <div class="mb-4 flex items-center gap-2.5">
                        <flux:icon.server-stack class="size-[17px] text-accent" />
                        <p class="flex-1 text-sm text-fg-bright">{{ $editing !== null ? __('Edit :name', ['name' => $name]) : __('Add a share') }}</p>
                    </div>

                    <p class="mb-4 text-sm text-fg-soft">
                        {{ __('A folder on another machine — a Batocera box\'s share, a NAS — that Send to can copy games onto. The library keeps its files; only copies go out.') }}
                    </p>

                    {{-- Step one: find the machine, so nobody has to know its address. --}}
                    <div class="mb-5">
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
                                <ul class="mt-3 flex flex-col gap-1.5">
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
                    </div>

                    <form wire:submit="save" class="flex flex-col gap-4">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <flux:input wire:model="host" :label="__('Machine')" placeholder="batocera"
                                        :description="__('Its name or address.')" />
                            <flux:input wire:model="name" :label="__('Name')" placeholder="Living room Batocera"
                                        :description="__('What Send to calls it.')" />
                        </div>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <flux:input wire:model="username" :label="__('Username')" :placeholder="__('Leave empty for a guest share')" autocomplete="off" />
                            <flux:input wire:model="password" type="password" :label="__('Password')" autocomplete="new-password"
                                        :placeholder="$editing !== null ? __('Unchanged unless typed') : ''"
                                        :description="__('Kept encrypted.')" />
                        </div>

                        {{-- Step two: ask the machine for its shares, and pick one. --}}
                        <div>
                            <flux:button size="sm" variant="ghost" icon="folder-open" type="button" wire:click="listShares" x-bind:disabled="$wire.listToken !== null">
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
                                    class="mt-2 flex items-center gap-2 text-sm text-fg-soft"
                                >
                                    <flux:icon.loading class="size-4" />
                                    {{ __('Asking :host for its shares…', ['host' => $host]) }}
                                </div>
                            @elseif ($this->listingProblem() !== null)
                                <p class="mt-2 text-sm text-danger">{{ $this->listingProblem() }}</p>
                            @elseif ($listing !== null && $listing['shares'] === [])
                                <p class="mt-2 text-sm text-fg-faint">{{ __('It answered, but shares nothing this account can see.') }}</p>
                            @endif
                        </div>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            @if (($listing['shares'] ?? []) !== [])
                                <flux:select wire:model="share" :label="__('Share')" :placeholder="__('Choose a share')">
                                    @foreach ($listing['shares'] as $shareName)
                                        <flux:select.option value="{{ $shareName }}">{{ $shareName }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            @else
                                <flux:input wire:model="share" :label="__('Share')" placeholder="share" />
                            @endif

                            <flux:input wire:model="folder" :label="__('Folder')" :placeholder="__('The share\'s root')"
                                        :description="__('The folder that holds roms/ — empty for Batocera\'s share. Not roms/ itself.')" />
                        </div>

                        <div class="flex gap-2">
                            <flux:button variant="primary" type="submit">{{ $editing !== null ? __('Save changes') : __('Save destination') }}</flux:button>
                            @if ($editing !== null)
                                <flux:button variant="ghost" type="button" wire:click="cancelEdit">{{ __('Cancel') }}</flux:button>
                            @endif
                        </div>
                    </form>
                </div>
            </div>

            <div class="flex flex-col gap-6 lg:col-span-5">
                <div class="rounded-xl border border-line bg-surface p-5">
                    <p class="kicker mb-3 text-fg-faint">{{ __('Saved') }}</p>

                    @if ($this->destinations->isEmpty())
                        <p class="text-sm text-fg-faint">{{ __('None yet. A USB drive on this computer needs no setting up: Send to offers it always.') }}</p>
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
            </div>
        </div>
    </x-settings.layout>
</section>
