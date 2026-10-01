{{--
    A USB transfer, wherever you are: the copying is $store.usb's
    (resources/js/usb-transfers.js), which lives as long as the tab, and this
    shows it in the corner of every page. Kept across wire:navigate by the
    layout's @persist, so it does not blink on every page.

    Every transfer in the queue has its own row and progress bar; one that
    fails is shown so, with why, and the queue goes on with the next.

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
    <div class="flex items-baseline justify-between gap-3">
        <p class="kicker text-fg-faint">
            <span x-show="$store.usb.status === 'running'">{{ __('Copying to the drive') }}</span>
            <span x-show="$store.usb.status === 'interrupted'">{{ __('Interrupted') }}</span>
            <span x-show="$store.usb.status === 'done' && $store.usb.failures.length === 0">{{ __('Copied to the drive') }}</span>
            <span x-show="$store.usb.status === 'done' && $store.usb.failures.length > 0">{{ __('Could not finish everything') }}</span>
            <span x-show="$store.usb.status === 'stopped'">{{ __('Stopped') }}</span>
        </p>
        <template x-if="$store.usb.jobs.length > 1">
            <span class="shrink-0 font-mono text-xs text-fg-faint" x-text="`${$store.usb.finished} / ${$store.usb.jobs.length}`"></span>
        </template>
    </div>

    <template x-if="$store.usb.status === 'interrupted'">
        <p class="mt-2 text-xs text-fg-soft">{{ __('The page was reloaded before it was done. What is already on the drive is kept; the rest follows.') }}</p>
    </template>

    {{-- One row per transfer, each with its own progress: one that fails
         says why here, and the next one goes on. --}}
    <ul class="mt-2 max-h-80 divide-y divide-line overflow-y-auto">
        <template x-for="job in $store.usb.jobs" :key="job.id">
            <li class="py-2">
                <div class="flex items-center justify-between gap-3">
                    <p class="min-w-0 truncate text-sm"
                       x-bind:class="job.state === 'waiting' || job.state === 'stopped' ? 'text-fg-soft' : 'text-fg-bright'"
                       x-text="job.label"></p>
                    <div class="flex shrink-0 items-center gap-2 text-xs">
                        <span x-show="job.state === 'waiting'" class="text-fg-faint">{{ __('Waiting') }}</span>
                        <span x-show="job.state === 'running'" class="font-mono text-fg-faint" x-text="`${$store.usb.percent(job)}%`"></span>
                        <span x-show="job.state === 'done'" class="text-fg-faint">{{ __('Copied') }}</span>
                        <span x-show="job.state === 'stopped'" class="text-fg-faint">{{ __('Stopped') }}</span>
                        <span x-show="job.state === 'error'" class="text-danger">{{ __('Failed') }}</span>
                        <template x-if="job.state === 'waiting' || job.state === 'running'">
                            <button type="button" class="text-fg-faint hover:text-fg-bright" x-on:click="$store.usb.cancel(job)"
                                    x-bind:title="job.state === 'running' ? @js(__('Stop this one')) : @js(__('Remove from the queue'))">&times;</button>
                        </template>
                    </div>
                </div>

                <template x-if="job.state === 'running'">
                    <div class="mt-2">
                        <div class="h-1.5 overflow-hidden rounded-sm bg-raised">
                            <div class="h-full rounded-sm bg-accent-deep transition-[width] duration-300" x-bind:style="`width: ${$store.usb.percent(job)}%`"></div>
                        </div>
                        <p class="mt-1.5 font-mono text-xs text-fg-faint" x-show="job.phase === 'checking'"
                           x-text="@js(__('Checking what the drive has')) + ` · ${job.checked} / ${job.checking}`"></p>
                        <p class="mt-1.5 font-mono text-xs text-fg-faint" x-show="job.phase === 'copying'"
                           x-text="`${$store.usb.size(job.written)} / ${$store.usb.size(job.total)}` + (job.left ? ` · ${job.left}` : '') + (job.skipped ? ` · ${job.skipped} ` + @js(__('already there')) : '')"></p>
                        <p class="mt-1.5 font-mono text-xs text-fg-faint" x-show="job.phase === 'gamelist'">{{ __('Writing the game list') }}…</p>
                    </div>
                </template>

                <template x-if="job.state === 'done' && job.skipped > 0">
                    <p class="mt-1 text-xs text-fg-faint" x-text="`${job.skipped} ` + @js(__('already on the drive were left as they were.'))"></p>
                </template>
                <template x-if="job.state === 'done' && job.removed > 0">
                    <p class="mt-1 text-xs text-fg-faint" x-text="`${job.removed} ` + @js(__('files of another version of the game were removed.'))"></p>
                </template>
                <template x-if="job.state === 'error'">
                    <p class="mt-1 text-xs break-words text-danger" x-text="job.message"></p>
                </template>
            </li>
        </template>
    </ul>

    <div class="mt-3 flex justify-end gap-2">
        <template x-if="$store.usb.status === 'interrupted'">
            <flux:button size="sm" variant="primary" x-on:click="$store.usb.resume()">{{ __('Resume') }}</flux:button>
        </template>
        <template x-if="$store.usb.status === 'running' || $store.usb.status === 'interrupted'">
            <flux:button size="sm" variant="ghost" x-on:click="$store.usb.stop()">
                <span x-text="$store.usb.jobs.length > 1 ? @js(__('Stop all')) : @js(__('Stop'))"></span>
            </flux:button>
        </template>
        <template x-if="$store.usb.status === 'running' && $store.usb.finished > 0">
            <flux:button size="sm" variant="ghost" x-on:click="$store.usb.dismiss()">{{ __('Clear finished') }}</flux:button>
        </template>
        <template x-if="['done', 'stopped'].includes($store.usb.status)">
            <flux:button size="sm" variant="ghost" x-on:click="$store.usb.dismiss()">{{ __('Dismiss') }}</flux:button>
        </template>
    </div>
</div>
