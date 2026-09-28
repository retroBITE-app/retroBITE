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
    // drive and share code either way, pointed at a different plan.
    $targets = collect(App\Transfers\TransferTargets::all())->mapWithKeys(fn ($target) => [$target->key() => [
        'label' => $target->label(),
        'plan' => $console !== null
            ? route('transfers.console-plan', ['target' => $target->key(), 'console' => $console->key])
            : route('transfers.plan', ['target' => $target->key(), 'gameId' => $game->id]),
        'gamelist' => $console !== null
            ? route('transfers.console-gamelist', ['target' => $target->key(), 'console' => $console->key])
            : route('transfers.gamelist', ['target' => $target->key(), 'gameId' => $game->id]),
    ]])->all();

    $shares = collect($destinations)->mapWithKeys(fn ($destination) => [(string) $destination->id => [
        'label' => $destination->name,
        'address' => $destination->address(),
    ]])->all();

    $localhost = config('transfer.localhost_url');
    $localhostHere = $localhost !== null ? rtrim($localhost, '/').'/'.ltrim(request()->path(), '/') : null;

    $subject = $console !== null
        ? trans_choice('{1} The one identified game on :console, one copy of it.|[2,*] All :count identified games on :console, one copy of each.', $count, ['count' => $count, 'console' => $console->name])
        : $game->title;
@endphp

<flux:modal name="transfer" class="w-full max-w-xl">
    <div x-data="transfer(@js(['targets' => $targets, 'shares' => $shares]))" class="flex flex-col gap-5">
        <div>
            <flux:heading size="lg">{{ __('Send to') }}</flux:heading>
            <flux:text class="mt-1">{{ $subject }}</flux:text>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <flux:select x-model="destination" :label="__('Destination')" x-bind:disabled="status === 'running'">
                <flux:select.option value="usb">{{ __('USB drive on this computer') }}</flux:select.option>
                @foreach ($shares as $id => $share)
                    <flux:select.option value="{{ $id }}">{{ $share['label'] }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select x-model="target" :label="__('Laid out for')" x-bind:disabled="status === 'running'">
                @foreach ($targets as $key => $target)
                    <flux:select.option value="{{ $key }}">{{ $target['label'] }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

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
                <p>{{ __('Writing to a drive needs Chrome or Edge, with this page opened through localhost on the machine retroBite runs on.') }}</p>
                @if ($localhostHere !== null)
                    <a href="{{ $localhostHere }}" class="mt-2 inline-block font-mono text-accent hover:underline">{{ $localhostHere }}</a>
                @endif
            </div>
        </template>

        <template x-if="destination === 'usb' && supported">
            <div class="flex items-center justify-between gap-3 rounded-lg border border-line-input bg-sunken px-4 py-3">
                <div class="min-w-0 text-sm">
                    <p class="kicker text-fg-faint">{{ __('Drive') }}</p>
                    <p class="mt-1 truncate font-mono text-fg-bright" x-text="driveName ?? @js(__('None chosen'))"></p>
                </div>
                <flux:button size="sm" variant="subtle" x-on:click="chooseDrive()" x-bind:disabled="status === 'running'">
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
                    <li class="truncate text-fg-faint" x-text="plan.gamelist"></li>
                </ul>
                <template x-if="plan.rejected > 0">
                    <p class="mt-2 text-xs text-fg-faint" x-text="`${plan.rejected} ` + @js(__('left out: their files are outside the console\'s folder.'))"></p>
                </template>
            </div>
        </template>

        <template x-if="status === 'confirm-root'">
            <div class="rounded-lg border border-accent-tint/55 bg-accent-tint/10 px-4 py-3 text-sm text-accent">
                <p>{{ __('This folder has no roms/ or batocera/roms/ in it. Use it as the root of the drive and create roms/ here?') }}</p>
                <div class="mt-3 flex gap-2">
                    <flux:button size="sm" variant="primary" x-on:click="start(true)">{{ __('Use this folder') }}</flux:button>
                    <flux:button size="sm" variant="subtle" x-on:click="status = 'idle'; chooseDrive()">{{ __('Choose another') }}</flux:button>
                </div>
            </div>
        </template>

        <template x-if="status === 'running'">
            <div>
                <div class="h-1.5 overflow-hidden rounded-sm bg-raised">
                    <div class="h-full rounded-sm bg-accent-deep transition-[width] duration-300" x-bind:style="`width: ${percent}%`"></div>
                </div>
                <p class="mt-2 flex justify-between gap-3 text-xs text-fg-faint">
                    <span class="truncate font-mono" x-text="current"></span>
                    <span class="shrink-0 font-mono" x-text="`${size(written)} / ${size(total)}`"></span>
                </p>
                <p class="mt-1 text-xs text-fg-faint">{{ __('Keep this tab open until it is done.') }}</p>
            </div>
        </template>

        <template x-if="status === 'done'">
            <p class="rounded-lg border border-line-input bg-sunken px-4 py-3 text-sm text-fg-soft">
                {{ __('Done.') }}
                <span x-show="skipped > 0" x-text="`${skipped} ` + @js(__('already on the drive were left as they were.'))"></span>
            </p>
        </template>

        <template x-if="status === 'error'">
            <p class="rounded-lg border border-danger/40 bg-danger/10 px-4 py-3 text-sm text-danger" x-text="message"></p>
        </template>

        <div class="flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="ghost" x-bind:disabled="status === 'running'">{{ __('Close') }}</flux:button>
            </flux:modal.close>
            <flux:button variant="primary" icon="arrow-up-tray" x-on:click="start()"
                         x-bind:disabled="status === 'running' || !plan || (destination === 'usb' && !supported)">
                {{ __('Start') }}
            </flux:button>
        </div>
    </div>
</flux:modal>
