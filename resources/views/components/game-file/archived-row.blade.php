@props(['row', 'branch' => true])

{{-- A file no longer in the library, kept as the record of which dump it
     was (docs/adr/0009): under the file made out of it, or in the history.
     Quieter than a live row and indented by how far back it is; what it
     keeps — checksum, serial, the provider's word — reads as on any row. --}}
<tr {{ $attributes->class('bg-surface/40 text-fg-muted') }}>
    <td class="px-4.5 py-2.5">
        <div class="flex items-center gap-2" style="padding-left: {{ $row['depth'] * 1.25 }}rem">
            @if ($branch)
                <span class="font-mono text-xs text-fg-faint" aria-hidden="true">└</span>
            @endif
            <span class="font-mono text-sm text-fg-soft">{{ $row['filename'] }}</span>
            <span class="shrink-0 rounded-md border border-dashed border-line-strong px-2 py-0.5 font-mono text-xs text-fg-faint">
                {{ __('Archived') }} · {{ $row['reason'] }}
            </span>
        </div>
        @if ($row['folder'] !== '' || $row['licenseId'])
            <div class="mt-0.5 flex flex-wrap items-center gap-2" style="padding-left: {{ $row['depth'] * 1.25 + ($branch ? 1.25 : 0) }}rem">
                @if ($row['folder'] !== '')
                    <span class="font-mono text-xs text-fg-faint">{{ $row['folder'] }}</span>
                @endif
                @if ($row['licenseId'])
                    <span class="rounded-md border border-line bg-surface px-2 py-0.5 font-mono text-xs text-fg-faint">{{ $row['licenseId'] }}</span>
                @endif
            </div>
        @endif
    </td>
    <td class="px-4.5 py-2.5 whitespace-nowrap">
        @if ($row['regionIcon'] !== null)
            <img src="{{ $row['regionIcon'] }}" alt="{{ $row['regionLabel'] }}" title="{{ $row['regionLabel'] }}" class="w-6 border border-line-input opacity-70" />
        @elseif ($row['region'] !== null)
            <span title="{{ $row['regionLabel'] }}" class="font-mono text-xs">{{ Str::upper($row['region']) }}</span>
        @else
            <span class="font-mono">—</span>
        @endif
    </td>
    <td class="px-4.5 py-2.5 font-mono whitespace-nowrap">
        {{ $row['scrapes'] ?? '—' }}
        @foreach ($row['flags'] as $flag)
            <span class="ml-1 rounded-md border border-warn/30 px-1.5 py-0.5 text-xs text-warn/70">{{ __(Str::headline($flag === 'trad' ? 'translation' : $flag)) }}</span>
        @endforeach
    </td>
    <td class="px-4.5 py-2.5 whitespace-nowrap">{{ $row['role'] }}</td>
    <td class="px-4.5 py-2.5 font-mono">{{ $row['disc'] }}</td>
    <td class="px-4.5 py-2.5 font-mono whitespace-nowrap">{{ $row['size'] }}</td>
    <td class="px-4.5 py-2.5 font-mono">{{ $row['format'] }}</td>
    <td class="px-4.5 py-2.5 font-mono whitespace-nowrap">{{ $row['added'] }}</td>
    <td class="px-4.5 py-2.5 font-mono whitespace-nowrap" title="{{ $row['reason'] }}">{{ $row['when'] }}</td>
    <td class="px-4.5 py-2.5">
        @if ($row['md5'])
            <div class="flex items-center gap-2">
                <span class="font-mono text-xs text-fg-faint">{{ $row['md5'] }}</span>
                <x-copy-button :text="$row['md5']" label="">
                    <flux:icon.document-duplicate class="size-3.5 text-fg-faint" />
                </x-copy-button>
            </div>
        @else
            <span class="font-mono text-xs text-fg-faint">{{ __('Never hashed') }}</span>
        @endif
    </td>
    {{-- Forgetting is the one thing to do with a row whose file is gone. --}}
    <td class="px-4.5 py-2.5 text-right">
        <button
            type="button"
            wire:click="forgetFile({{ $row['id'] }})"
            wire:confirm="{{ __('Forget :file? Its checksums and what the provider said of it go too.', ['file' => $row['filename']]) }}"
            aria-label="{{ __('Forget :file', ['file' => $row['filename']]) }}"
            title="{{ __('Forget') }}"
            class="cursor-pointer rounded-md p-1 text-fg-faint transition-colors hover:text-danger focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
        >
            <flux:icon.archive-box-x-mark class="size-3.5" />
        </button>
    </td>
</tr>
