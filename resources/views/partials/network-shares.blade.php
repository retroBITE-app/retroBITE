@php
    use App\Enums\ShareProtocol;

    $whitelist = (string) config('settings.network.ps3netsrv_whitelist');
@endphp

{{--
    Drawn twice by livewire/network-shares: once as the placeholder, with
    $status null because the probe has not run yet, and again once it has. The
    same markup both times so the cards do not shift when the answer lands.
--}}
<section class="space-y-4">
    <h2 class="text-lg font-medium text-fg-bright">{{ __('Network shares') }}</h2>

    <div class="grid gap-4 lg:grid-cols-2">
        @foreach (ShareProtocol::enabled() as $protocol)
            @php($online = $status === null ? null : ($status[$protocol->value] ?? false))
            @php($served = $protocol->serves($shares))

            <div class="flex flex-col overflow-hidden rounded-xl border border-line bg-sunken">
                <div class="flex items-center justify-between border-b border-line/70 px-3.5 py-3">
                    <h3 class="text-sm text-fg-bright">{{ $protocol->label() }}</h3>

                    <span class="flex items-center gap-2">
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
                </div>

                <div class="space-y-4 px-3.5 py-3.5">
                    <dl class="grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <dt class="text-fg-faint">{{ __('Host') }}</dt>
                            <dd class="mt-0.5 font-mono text-sm text-fg-soft">{{ $hostIp }}</dd>
                        </div>
                        <div>
                            <dt class="text-fg-faint">{{ __('Ports') }}</dt>
                            <dd class="mt-0.5 font-mono text-sm text-fg-soft">{{ $protocol->displayPorts() }}</dd>
                        </div>
                        <div>
                            <dt class="text-fg-faint">{{ __('Credentials') }}</dt>
                            <dd class="mt-0.5 font-mono text-sm text-fg-soft">{{ $protocol->authenticated() ? $shareUser.' / ******' : __('None') }}</dd>
                        </div>
                    </dl>

                    @if ($protocol === ShareProtocol::Ps3netsrv)
                        {{-- It has no login, so who may connect is the whole of its security. --}}
                        @if ($whitelist === '')
                            <p class="text-xs text-warn">{{ __('Readable by everyone on the network. Limit it with PS3NETSRV_WHITELIST.') }}</p>
                        @elseif (! ShareProtocol::ps3netsrvWhitelistValid($whitelist))
                            <p class="text-xs text-danger">{{ __('PS3NETSRV_WHITELIST=:whitelist is not an address ps3netsrv takes (one address, * for any part), so it is not started.', ['whitelist' => $whitelist]) }}</p>
                        @else
                            <p class="text-xs text-fg-dim">{{ __('Only :whitelist may connect.', ['whitelist' => $whitelist]) }}</p>
                        @endif
                    @endif

                    <div>
                        <p class="kicker mb-2 text-fg-faint">{{ __('Shares') }}</p>

                        @if ($served->isEmpty())
                            <div class="rounded-xl border border-dashed border-line-input px-6 py-10 text-center">
                                <p class="text-sm text-fg-soft">{{ $shares->isEmpty() ? __('No consoles installed yet.') : __('None of the consoles it serves is installed.') }}</p>
                            </div>
                        @else
                            <ul class="space-y-1">
                                @foreach ($served as $share)
                                    @php($misplaced = $protocol->misplaced($share))
                                    <li class="rounded-md border border-line/70 bg-surface px-3 py-2">
                                        <div class="flex items-center gap-2">
                                            <img src="{{ $share->icon }}" alt="" class="h-4 w-4 shrink-0 object-contain" />
                                            <span class="min-w-0 flex-1 truncate text-sm text-fg-soft">{{ $share->name }}</span>
                                            @if ($misplaced !== null)
                                                <flux:icon.exclamation-triangle variant="micro" class="size-4 shrink-0 text-warn" />
                                            @else
                                                <span class="min-w-0 shrink truncate font-mono text-xs text-fg-dim">{{ $protocol->connectionString($hostIp, $protocol === ShareProtocol::Ps3netsrv ? $protocol->servedFolder($share) : $share->folder) }}</span>
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
            </div>
        @endforeach
    </div>
</section>
