{{--
    A USB transfer, wherever you are: the copying is $store.usb's
    (resources/js/usb-transfers.js), which lives as long as the tab, and this
    shows it in the corner of every page. Kept across wire:navigate by the
    layout's @persist, so it does not blink on every page.

    After a reload it says what was left unfinished. Chrome sometimes keeps the
    drive's permission, and then it goes on by itself; otherwise Resume is the
    click the permission has to be asked on.
--}}
<div
    x-data
    x-init="$store.usb.configure(@js([
        'underMinute' => __('less than a minute left'),
        'minutes' => __('about :n min left'),
        'hours' => __('about :h h :m min left'),
        'wholeHours' => __('about :h h left'),
    ]))"
    x-show="$store.usb.visible"
    x-cloak
    x-transition.opacity
    data-transfer-tray
    class="fixed right-4 bottom-4 z-40 w-[calc(100vw-2rem)] max-w-sm rounded-lg border border-line-bright bg-surface/95 px-4 py-3 shadow-lg backdrop-blur-sm"
>
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="kicker text-fg-faint">
                <span x-show="$store.usb.status === 'running'">{{ __('Copying to the drive') }}</span>
                <span x-show="$store.usb.status === 'interrupted'">{{ __('Interrupted') }}</span>
                <span x-show="$store.usb.status === 'done'">{{ __('Copied to the drive') }}</span>
                <span x-show="$store.usb.status === 'stopped'">{{ __('Stopped') }}</span>
                <span x-show="$store.usb.status === 'error'">{{ __('Could not finish') }}</span>
            </p>
            <p class="mt-1 truncate text-sm text-fg-bright" x-text="$store.usb.label"></p>
        </div>
        <template x-if="$store.usb.waiting > 0">
            <span class="shrink-0 text-xs text-fg-faint" x-text="`+${$store.usb.waiting} ` + @js(__('waiting'))"></span>
        </template>
    </div>

    <template x-if="$store.usb.status === 'running'">
        <div class="mt-3">
            <div class="h-1.5 overflow-hidden rounded-sm bg-raised">
                <div class="h-full rounded-sm bg-accent-deep transition-[width] duration-300" x-bind:style="`width: ${$store.usb.percent}%`"></div>
            </div>
            <p class="mt-2 font-mono text-xs text-fg-faint" x-text="`${$store.usb.size($store.usb.written)} / ${$store.usb.size($store.usb.total)}` + ($store.usb.left ? ` · ${$store.usb.left}` : '')"></p>
        </div>
    </template>

    <template x-if="$store.usb.status === 'interrupted'">
        <p class="mt-2 text-xs text-fg-soft">{{ __('The page was reloaded before it was done. What is already on the drive is kept; the rest follows.') }}</p>
    </template>

    <template x-if="$store.usb.status === 'done' && $store.usb.skipped > 0">
        <p class="mt-2 text-xs text-fg-faint" x-text="`${$store.usb.skipped} ` + @js(__('already on the drive were left as they were.'))"></p>
    </template>

    <template x-if="$store.usb.status === 'error'">
        <p class="mt-2 text-xs text-danger" x-text="$store.usb.message"></p>
    </template>

    <div class="mt-3 flex justify-end gap-2">
        <template x-if="$store.usb.status === 'interrupted'">
            <flux:button size="sm" variant="primary" x-on:click="$store.usb.resume()">{{ __('Resume') }}</flux:button>
        </template>
        <template x-if="$store.usb.status === 'running' || $store.usb.status === 'interrupted'">
            <flux:button size="sm" variant="ghost" x-on:click="$store.usb.stop()">{{ __('Stop') }}</flux:button>
        </template>
        <template x-if="['done', 'stopped', 'error'].includes($store.usb.status)">
            <flux:button size="sm" variant="ghost" x-on:click="$store.usb.dismiss()">{{ __('Dismiss') }}</flux:button>
        </template>
    </div>
</div>
