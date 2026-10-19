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
                <x-badge size="sm" tone="muted" dashed>{{ __('Archived') }} · {{ $row['reason'] }}</x-badge>
            </div>

            <x-game-file.folder :path="$row['folder']" class="mt-1" />
        </div>

        {{-- Forgetting is the one thing to do with a file that is gone. --}}
        <x-icon-button
            icon="archive-box-x-mark"
            :label="__('Forget :file', ['file' => $row['filename']])"
            tone="danger"
            wire:click="forgetFile({{ $row['id'] }})"
            wire:confirm="{{ __('Forget :file? Its checksums and what the provider said of it go too.', ['file' => $row['filename']]) }}"
            class="-mt-1 -mr-1"
        />
    </div>

    <dl class="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-4 gap-y-1.5 rounded-md border border-line bg-sunken/60 px-3 py-2">
        @if ($row['md5'])
            <x-game-file.field :label="__('MD5')" :copy="$row['md5']" class="text-fg-faint">{{ $row['md5'] }}</x-game-file.field>
        @endif

        <x-game-file.field :label="__('Archived')" :title="$row['reason']" class="text-fg-muted">{{ $row['when'] }}</x-game-file.field>
    </dl>

    <x-game-file.badges :row="$row" archived class="border-t border-raised pt-2.5 text-fg-faint" />
</li>
