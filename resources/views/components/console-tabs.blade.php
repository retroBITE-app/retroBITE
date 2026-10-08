@props(['consoles', 'selected'])

{{-- One tab per console, for a page using App\Concerns\PicksConsoleTab:
     each calls the page's selectConsole(). --}}
<div {{ $attributes->class('flex flex-wrap items-center gap-1 border-b border-line') }}>
    @foreach ($consoles as $key => $console)
        <button
            type="button"
            wire:key="tab-{{ $key }}"
            wire:click="selectConsole(@js($key))"
            @class([
                'flex cursor-pointer items-center gap-2 rounded-t-lg border-b-2 px-3 py-2 text-sm transition-colors',
                'border-accent text-accent' => $key === $selected,
                'border-transparent text-fg-muted hover:text-fg-soft' => $key !== $selected,
            ])
        >
            <img src="{{ $console->icon }}" alt="" class="size-4 object-contain" />
            {{ $console->name }}
        </button>
    @endforeach
</div>
