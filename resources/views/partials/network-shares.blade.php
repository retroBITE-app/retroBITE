@php
    use App\Enums\ShareProtocol;
    use Illuminate\Support\Arr;

    $whitelist = (string) config('settings.network.ps3netsrv_whitelist');
    $whitelistValid = $whitelist !== '' && ShareProtocol::ps3netsrvWhitelistValid($whitelist);
@endphp

{{--
    Drawn twice by livewire/network-shares: once as the placeholder, with
    $status null because the probe has not run yet, and again once it has. The
    same markup both times so the rows do not shift when the answer lands.

    One panel, a row per protocol: the host is said once in the heading, and
    each row's share list folds away under it (shareRows, resources/js/
    share-rows.js, remembers which are open). On a phone a row folds into two
    lines: the protocol and its status, then ports · login · shares.
--}}
<section class="space-y-3" x-data="shareRows">
    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <h2 class="text-lg font-medium text-fg-bright">{{ __('Network shares') }}</h2>

        <span class="flex items-center font-mono text-sm text-fg-soft">
            {{ $hostIp }}
            <x-copy-button :text="$hostIp" label="">
                <flux:icon.document-duplicate class="size-3.5 text-fg-faint" />
            </x-copy-button>
        </span>
    </div>

    <div class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-sunken">
        <div class="kicker hidden grid-cols-[8rem_7.5rem_7rem_minmax(0,1fr)_7rem] gap-x-4 px-4 py-2 text-fg-faint md:grid">
            <span>{{ __('Protocol') }}</span>
            <span>{{ __('Status') }}</span>
            <span>{{ __('Ports') }}</span>
            <span>{{ __('Login') }}</span>
            <span class="text-right">{{ __('Shares') }}</span>
        </div>

        @foreach (ShareProtocol::enabled() as $protocol)
            @php($online = $status === null ? null : (bool) Arr::get($status, $protocol->value, false))
            @php($served = $protocol->serves($shares))
            @php($key = $protocol->value)

            <div wire:key="protocol-{{ $key }}">
                <button
                    type="button"
                    x-on:click="toggle(@js($key))"
                    x-bind:aria-expanded="isOpen(@js($key))"
                    class="grid w-full cursor-pointer grid-cols-[1fr_auto] items-center gap-x-4 gap-y-1 px-4 py-3 text-left transition-colors hover:bg-hover md:grid-cols-[8rem_7.5rem_7rem_minmax(0,1fr)_7rem]"
                >
                    <span class="text-sm text-fg-bright">{{ $protocol->label() }}</span>

                    <span class="flex items-center gap-2 justify-self-end md:justify-self-start">
                        <span @class([
                            'h-2 w-2 shrink-0 rounded-full',
                            'animate-pulse bg-fg-faint' => $online === null,
                            'bg-accent shadow-glow' => $online === true,
                            'bg-danger' => $online === false,
                        ])></span>
                        <span @class([
                            'text-sm',
                            'text-fg-dim' => $online === null,
                            'text-accent' => $online === true,
                            'text-danger' => $online === false,
                        ])>{{ match ($online) { null => __('Checking…'), true => __('Online'), false => __('Offline') } }}</span>
                    </span>

                    {{-- Every row the same two lines on a phone: ports · login on one
                         line that never wraps, the share count pinned beside it. From
                         md up `contents` hands ports and login to the row's grid. --}}
                    <span class="flex min-w-0 items-center gap-x-2 overflow-hidden text-sm whitespace-nowrap md:contents">
                        <span class="shrink-0 font-mono text-fg-soft">{{ $protocol->displayPorts() }}</span>
                        <span class="shrink-0 text-fg-faint md:hidden">·</span>

                        <span class="flex min-w-0 items-center gap-x-2">
                            @if ($protocol->authenticated())
                                <span class="truncate font-mono text-fg-soft">{{ $shareUser }} / ******</span>
                            @else
                                {{-- No login, so who may connect is the whole of its security. --}}
                                <span class="shrink-0 font-mono text-fg-soft">{{ __('none') }}</span>
                                @if ($protocol === ShareProtocol::Ps3netsrv)
                                    {{-- On a phone this sits at the top of the opened list instead. --}}
                                    <span @class([
                                        'hidden truncate text-xs md:inline',
                                        'text-fg-dim' => $whitelistValid,
                                        'text-warn' => $whitelist === '',
                                        'text-danger' => $whitelist !== '' && ! $whitelistValid,
                                    ])>· {{ match (true) {
                                        $whitelistValid => __(':whitelist only', ['whitelist' => $whitelist]),
                                        $whitelist === '' => __('anyone on the LAN'),
                                        default => __('not started'),
                                    } }}</span>
                                    <span class="shrink-0 rounded-md border border-line-strong px-1.5 py-0.5 font-mono text-[10px] tracking-kicker text-fg-muted uppercase">{{ __('Read-only') }}</span>
                                @endif
                            @endif
                        </span>
                    </span>

                    <span class="flex items-center gap-1 justify-self-end text-sm whitespace-nowrap text-fg-muted">
                        {{ trans_choice(':count share|:count shares', $served->count(), ['count' => $served->count()]) }}
                        <flux:icon.chevron-down variant="micro" class="size-4 transition-transform duration-200" x-bind:class="isOpen(@js($key)) && '-rotate-180'" />
                    </span>
                </button>

                {{-- Why a row is not well, said under it rather than hidden in the list. --}}
                @if ($online === false)
                    <p class="px-4 pb-3 text-xs text-danger">{{ __('Not answering on :port. Is the share container running?', ['port' => $protocol->port()]) }}</p>
                @endif

                @if ($protocol === ShareProtocol::Ps3netsrv && $whitelist === '')
                    <p class="px-4 pb-3 text-xs text-warn">{{ __('Readable by everyone on the network. Limit it with PS3NETSRV_WHITELIST.') }}</p>
                @elseif ($protocol === ShareProtocol::Ps3netsrv && ! $whitelistValid)
                    <p class="px-4 pb-3 text-xs text-danger">{{ __('PS3NETSRV_WHITELIST=:whitelist is not an address ps3netsrv takes (one address, * for any part), so it is not started.', ['whitelist' => $whitelist]) }}</p>
                @endif

                <div x-show="isOpen(@js($key))" x-cloak class="px-4 pb-3">
                    @if ($protocol === ShareProtocol::Ps3netsrv && $whitelistValid)
                        <p class="mb-2 text-xs text-fg-dim md:hidden">{{ __('Only :whitelist may connect.', ['whitelist' => $whitelist]) }}</p>
                    @endif

                    @if ($served->isEmpty())
                        <p class="rounded-md border border-dashed border-line-input px-4 py-4 text-center text-sm text-fg-soft">
                            {{ $shares->isEmpty() ? __('No consoles installed yet.') : __('None of the consoles it serves is installed.') }}
                        </p>
                    @else
                        <ul class="space-y-1">
                            @foreach ($served as $share)
                                @php($misplaced = $protocol->misplaced($share))
                                @php($connection = $protocol->connectionString($hostIp, $protocol === ShareProtocol::Ps3netsrv ? $protocol->servedFolder($share) : $share->folder))

                                <li wire:key="share-{{ $key }}-{{ $share->key }}" class="rounded-md border border-line/70 bg-surface px-3 py-1.5">
                                    <div class="flex items-center gap-2">
                                        <img src="{{ $share->icon }}" alt="" class="h-4 w-4 shrink-0 object-contain" />
                                        <span class="min-w-0 flex-1 truncate text-sm text-fg-soft">{{ $share->name }}</span>
                                        @if ($misplaced !== null)
                                            <flux:icon.exclamation-triangle variant="micro" class="size-4 shrink-0 text-warn" />
                                        @else
                                            <span class="min-w-0 shrink truncate font-mono text-xs text-fg-dim">{{ $connection }}</span>
                                            <x-copy-button :text="$connection" label="" class="px-1!">
                                                <flux:icon.document-duplicate class="size-3.5 text-fg-faint" />
                                            </x-copy-button>
                                        @endif
                                    </div>
                                    @if ($misplaced !== null)
                                        {{-- ps3netsrv reads PS3NETSRV_FOLDERS, not the consoles page. --}}
                                        <p class="mt-1 text-xs text-warn">{{ __('Its games are in :real, but ps3netsrv serves :served. Move the folder, or change PS3NETSRV_FOLDERS.', ['real' => $misplaced, 'served' => $share->folder]) }}</p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</section>
