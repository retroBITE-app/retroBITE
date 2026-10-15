@props(['row', 'branch' => true])

{{-- A file no longer in the library, kept as the record of which dump it
     was (docs/adr/0009): under the file made out of it, or in the history.
     Quieter than a live card and indented by how far back it is; what it
     keeps — checksum, serial, the provider's word — reads as on any card. --}}
<li
    {{ $attributes->class('flex flex-col gap-2.5 rounded-lg border border-dashed border-line-strong bg-surface/40 px-4 py-3 text-fg-muted') }}
    style="margin-left: {{ $row['depth'] * 1.25 }}rem"
>
    <div class="flex items-start gap-3">
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                @if ($branch)
                    <span class="font-mono text-xs text-fg-faint" aria-hidden="true">└</span>
                @endif
                <x-game-file.name :filename="$row['filename']" class="text-sm font-medium text-fg-soft" />
                <span class="shrink-0 rounded-md border border-dashed border-line-strong px-2 py-0.5 font-mono text-xs text-fg-faint">
                    {{ __('Archived') }} · {{ $row['reason'] }}
                </span>
            </div>

            @if ($row['folder'] !== '')
                <p class="mt-1 font-mono text-xs break-all text-fg-faint">{{ $row['folder'] }}</p>
            @endif
        </div>

        {{-- Forgetting is the one thing to do with a file that is gone. --}}
        <button
            type="button"
            wire:click="forgetFile({{ $row['id'] }})"
            wire:confirm="{{ __('Forget :file? Its checksums and what the provider said of it go too.', ['file' => $row['filename']]) }}"
            aria-label="{{ __('Forget :file', ['file' => $row['filename']]) }}"
            title="{{ __('Forget') }}"
            class="-mt-1 -mr-1.5 shrink-0 cursor-pointer rounded-md p-1.5 text-fg-faint transition-colors hover:text-danger focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
        >
            <flux:icon.archive-box-x-mark class="size-4" />
        </button>
    </div>

    <dl class="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-4 gap-y-1.5 rounded-md border border-line bg-sunken/60 px-3 py-2">
        @if ($row['md5'])
            <x-game-file.field :label="__('MD5')" :copy="$row['md5']" class="text-fg-faint">{{ $row['md5'] }}</x-game-file.field>
        @endif

        <x-game-file.field :label="__('Archived')" :title="$row['reason']" class="text-fg-muted">{{ $row['when'] }}</x-game-file.field>
    </dl>

    <x-game-file.badges :row="$row" archived class="border-t border-raised pt-2.5 text-fg-faint" />
</li>
