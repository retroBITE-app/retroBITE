@if ($this->docs->isEmpty())
    <div class="rounded-xl border border-dashed border-line-input px-4 py-8 text-center">
        <p class="text-xs text-fg-faint">{{ __('No documents yet.') }}</p>
    </div>
@else
    <div class="overflow-hidden rounded-xl border border-line">
        @foreach ($this->docs as $doc)
            <button
                type="button"
                wire:key="rail-{{ $doc->path }}"
                wire:click="select(@js($doc->path))"
                @class([
                    'cursor-pointer block w-full border-b border-line px-4 py-3 text-left transition-colors last:border-b-0',
                    'bg-accent-tint/10 shadow-rail' => $doc->path === $path,
                    'bg-surface hover:bg-hover' => $doc->path !== $path,
                ])
            >
                <div class="mb-1.5 flex items-center justify-between gap-2">
                    <span class="rounded border border-line-input bg-sunken px-1.5 py-0.5 font-mono text-[0.625rem] tracking-kicker text-fg-dim uppercase">
                        {{ $doc->console !== '' ? Str::upper($doc->console) : __('Doc') }}
                    </span>
                    <span class="font-mono text-[0.625rem] text-fg-faint">
                        {{ $doc->updatedAt->diffForHumans(short: true, syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}
                    </span>
                </div>

                <p @class([
                    'truncate text-sm',
                    'text-accent' => $doc->path === $path,
                    'text-fg-soft' => $doc->path !== $path,
                ])>{{ $doc->title }}</p>

                @if ($doc->subtitle() !== '')
                    <p class="mt-0.5 truncate font-mono text-[0.625rem] text-fg-faint">{{ $doc->subtitle() }}</p>
                @endif
            </button>
        @endforeach
    </div>
@endif
