{{--
    Send one game somewhere, from the game page's Actions menu — or a whole
    console, from its shelf: a USB drive on this computer, or a saved network
    share.

    The server decides what is written either way (App\Transfers). A USB drive
    is written by the browser, in resources/js/transfer.js, which needs Chrome
    or Edge on a secure context — here, the page opened through localhost on
    the machine that runs retroBite. A share is written by FileTransferJob on
    the server, so it works from any browser; Start hands it to the
    surrounding component's sendToShare() — the game page's, or
    games.send-console's. See docs/adr/0003 and 0004.
--}}
@props(['game' => null, 'console' => null, 'count' => 0, 'destinations'])

@php
    // One game from its page, or a whole console from its shelf: the same
    // drive and share code either way, pointed at a different plan. Only the
    // systems that play the console's games, the one its library is laid out
    // for first.
    $sentConsole = $console ?? $game->console();
    $available = $sentConsole !== null ? App\Transfers\TransferTargets::for($sentConsole) : App\Transfers\TransferTargets::all();
    $recommended = $sentConsole !== null ? App\Transfers\TransferTargets::recommendedFor($sentConsole)?->key() : null;

    $targets = collect($available)->mapWithKeys(function (App\Transfers\TransferTarget $target) use ($console, $game): array {
        $folders = collect($target->root())->map(function (string $folder): string {
            return $folder.'/';
        });

        return [$target->key() => [
            'label' => $target->label(),
            'hint' => $target->hint(),
            'root' => $target->root(),
            // What to ask when the chosen folder has none of the target's own.
            'confirm' => $folders->isEmpty() ? '' : __('This folder has no :folders in it. Use it as the root of the drive and create :first here?', [
                'folders' => $folders->join(', ', ' '.__('or').' '),
                'first' => $folders->first(),
            ]),
            'plan' => $console !== null
                ? route('transfers.console-plan', ['target' => $target->key(), 'console' => $console->key])
                : route('transfers.plan', ['target' => $target->key(), 'gameId' => $game->id]),
            'gamelist' => $console !== null
                ? route('transfers.console-gamelist', ['target' => $target->key(), 'console' => $console->key])
                : route('transfers.gamelist', ['target' => $target->key(), 'gameId' => $game->id]),
        ]];
    })->all();

    $shares = collect($destinations)->mapWithKeys(fn ($destination) => [(string) $destination->id => [
        'label' => $destination->name,
        'address' => $destination->address(),
    ]])->all();

    // Joined to this page's path in the browser, not from request(): the
    // modal is re-rendered by the page's Livewire updates, whose request is
    // livewire/update, not the page.
    $localhost = config('transfer.localhost_url');

    $subject = $console !== null
        ? trans_choice('{1} The one identified game on :console, one copy of it.|[2,*] All :count identified games on :console, one copy of each.', $count, ['count' => $count, 'console' => $console->name])
        : $game->title;
@endphp

<flux:modal name="transfer" class="w-full max-w-xl">
    <div x-data="transfer(@js(['targets' => $targets, 'target' => $recommended, 'shares' => $shares, 'label' => $console?->name ?? $game?->title]))" class="flex flex-col gap-5">
        <div>
            <flux:heading size="lg">{{ __('Send to') }}</flux:heading>
            <flux:text class="mt-1">{{ $subject }}</flux:text>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <flux:select x-model="destination" :label="__('Destination')">
                <flux:select.option value="usb">{{ __('USB drive on this computer') }}</flux:select.option>
                @foreach ($shares as $id => $share)
                    <flux:select.option value="{{ $id }}">{{ $share['label'] }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select x-model="target" :label="__('Laid out for')">
                @foreach ($targets as $key => $target)
                    <flux:select.option value="{{ $key }}">{{ $target['label'] }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <template x-if="targets[target].hint">
            <p class="-mt-2 text-xs text-fg-faint" x-text="targets[target].hint"></p>
        </template>

        @if ($shares === [])
            <p class="-mt-2 text-xs text-fg-faint">
                {{ __('A network share, such as a Batocera box\'s, can be added under') }}
                <a href="{{ route('destinations.edit') }}" wire:navigate class="text-accent hover:underline">{{ __('Settings → Destinations') }}</a>.
            </p>
        @endif

        {{-- A share: the server copies, from any browser. --}}
        <template x-if="destination !== 'usb'">
            <div class="flex items-center gap-3 rounded-lg border border-line-input bg-sunken px-4 py-3">
                <flux:icon.server-stack class="size-4 shrink-0 text-fg-muted" />
                <p class="min-w-0 truncate font-mono text-sm text-fg-bright" x-text="shares[destination]?.address"></p>
            </div>
        </template>

        {{-- A USB drive: the browser copies, where it can. --}}
        <template x-if="destination === 'usb' && !supported">
            <div class="rounded-lg border border-line-input bg-sunken px-4 py-3 text-sm text-fg-soft">
                <p>{{ __('Writing to a drive needs Chromium or Edge, with this page opened through localhost on the machine retroBite runs on.') }}</p>
                @if ($localhost !== null)
                    <a x-data="{ href: @js(rtrim($localhost, '/')) + window.location.pathname + window.location.search }"
                       x-bind:href="href" x-text="href"
                       class="mt-2 inline-block font-mono text-accent hover:underline"></a>
                @endif

                {{-- Chromium has the API; some of its browsers ship it switched off. --}}
                <p class="mt-2">{{ __('Brave and some other Chromium browsers turn drive access off. In Brave:') }}</p>
                <ol class="mt-1 list-decimal space-y-0.5 pl-5">
                    <li>{{ __('Open') }} <span class="font-mono break-all text-fg-bright">brave://flags/#file-system-access-api</span></li>
                    <li>{{ __('Set it to Enabled.') }}</li>
                    <li>{{ __('Relaunch Brave.') }}</li>
                </ol>
            </div>
        </template>

        <template x-if="destination === 'usb' && supported">
            <div class="flex items-center justify-between gap-3 rounded-lg border border-line-input bg-sunken px-4 py-3">
                <div class="min-w-0 text-sm">
                    <p class="kicker text-fg-faint">{{ __('Drive') }}</p>
                    <p class="mt-1 truncate font-mono text-fg-bright" x-text="driveName ?? @js(__('None chosen'))"></p>
                </div>
                <flux:button size="sm" variant="subtle" x-on:click="chooseDrive()" x-bind:disabled="$store.usb.status === 'running'">
                    <span x-text="driveName ? @js(__('Change')) : @js(__('Choose'))"></span>
                </flux:button>
            </div>
        </template>

        {{-- What will be written, before anything is. --}}
        <template x-if="plan">
            <div class="text-sm">
                <p class="kicker text-fg-faint">
                    <span x-text="targets[target].label"></span> · <span x-text="size(plan.bytes)"></span>
                    <template x-if="plan.games > 1">
                        <span> · <span x-text="plan.games"></span> {{ __('games') }} · <span x-text="plan.files.length"></span> {{ __('files') }}</span>
                    </template>
                </p>
                {{-- A console is thousands of files: the first few say what
                     the layout looks like, and the count says the rest. --}}
                <ul class="mt-2 max-h-40 overflow-y-auto font-mono text-xs text-fg-soft">
                    <template x-for="file in plan.files.slice(0, 100)" :key="file.destination">
                        <li class="truncate" x-text="file.destination"></li>
                    </template>
                    <template x-if="plan.files.length > 100">
                        <li class="truncate text-fg-faint" x-text="`… ${plan.files.length - 100} ` + @js(__('more'))"></li>
                    </template>
                    <template x-for="extra in plan.extras.slice(0, 100)" :key="extra.destination">
                        <li class="truncate" x-text="extra.destination"></li>
                    </template>
                    <template x-if="plan.gamelist">
                        <li class="truncate text-fg-faint" x-text="plan.gamelist"></li>
                    </template>
                </ul>
                <template x-if="plan.rejected > 0">
                    <p class="mt-2 text-xs text-fg-faint" x-text="`${plan.rejected} ` + @js(__('left out: they cannot be laid out this way.'))"></p>
                </template>
            </div>
        </template>

        <template x-if="status === 'confirm-root'">
            <div class="rounded-lg border border-accent-tint/55 bg-accent-tint/10 px-4 py-3 text-sm text-accent">
                <p x-text="targets[target].confirm"></p>
                <div class="mt-3 flex gap-2">
                    <flux:button size="sm" variant="primary" x-on:click="start(true)">{{ __('Use this folder') }}</flux:button>
                    <flux:button size="sm" variant="subtle" x-on:click="status = 'idle'; chooseDrive()">{{ __('Choose another') }}</flux:button>
                </div>
            </div>
        </template>

        {{-- The drive's copying is the tab's, not this modal's: it shows
             here while the modal is open and in the tray when it is not. --}}
        <template x-if="destination === 'usb' && $store.usb.status === 'running'">
            <div>
                <p class="mb-2 flex justify-between gap-3 text-sm text-fg-soft">
                    <span class="truncate" x-text="$store.usb.label"></span>
                    <template x-if="$store.usb.waiting > 0">
                        <span class="shrink-0 text-xs text-fg-faint" x-text="`+${$store.usb.waiting} ` + @js(__('waiting'))"></span>
                    </template>
                </p>
                <div class="h-1.5 overflow-hidden rounded-sm bg-raised">
                    <div class="h-full rounded-sm bg-accent-deep transition-[width] duration-300" x-bind:style="`width: ${$store.usb.percent}%`"></div>
                </div>
                <p class="mt-2 flex justify-between gap-3 text-xs text-fg-faint">
                    <span class="truncate font-mono" x-text="$store.usb.current"></span>
                    <span class="shrink-0 font-mono" x-text="`${size($store.usb.written)} / ${size($store.usb.total)}` + ($store.usb.left ? ` · ${$store.usb.left}` : '')"></span>
                </p>
                <p class="mt-1 text-xs text-fg-faint">{{ __('It carries on if you close this or move to another page; keep the tab open until it is done.') }}</p>
            </div>
        </template>

        <template x-if="destination === 'usb' && $store.usb.status === 'done'">
            <p class="rounded-lg border border-line-input bg-sunken px-4 py-3 text-sm text-fg-soft">
                {{ __('Done.') }}
                <span x-show="$store.usb.skipped > 0" x-text="`${$store.usb.skipped} ` + @js(__('already on the drive were left as they were.'))"></span>
            </p>
        </template>

        <template x-if="destination === 'usb' && $store.usb.status === 'error'">
            <p class="rounded-lg border border-danger/40 bg-danger/10 px-4 py-3 text-sm text-danger" x-text="$store.usb.message"></p>
        </template>

        <template x-if="status === 'error'">
            <p class="rounded-lg border border-danger/40 bg-danger/10 px-4 py-3 text-sm text-danger" x-text="message"></p>
        </template>

        <div class="flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="ghost">
                    <span x-text="$store.usb.status === 'running' ? @js(__('Keep going in the background')) : @js(__('Close'))"></span>
                </flux:button>
            </flux:modal.close>
            <flux:button variant="primary" icon="arrow-up-tray" x-on:click="start()"
                         x-bind:disabled="!plan || (destination === 'usb' && !supported)">
                <span x-text="destination === 'usb' && $store.usb.status === 'running' ? @js(__('Add to the queue')) : @js(__('Start'))"></span>
            </flux:button>
        </div>
    </div>
</flux:modal>
