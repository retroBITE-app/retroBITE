@props(['consoles', 'selected'])

{{-- One tab per console, for a page using App\Concerns\PicksConsoleTab:
     each calls the page's selectConsole(). One row that scrolls sideways when
     the consoles outrun the screen, as the settings and game-page tabs do,
     kept on the current tab by tabStrip (resources/js/tab-strip.js). --}}
<div x-data="tabStrip" {{ $attributes->class('flex items-center gap-1 overflow-x-auto border-b border-line') }}>
    @foreach ($consoles as $key => $console)
        <button
            type="button"
            wire:key="tab-{{ $key }}"
            wire:click="selectConsole(@js($key))"
            @if ($key === $selected) aria-current="page" @endif
            @class([
                'flex shrink-0 cursor-pointer items-center gap-2 rounded-t-lg border-b-2 px-3 py-2 text-sm whitespace-nowrap transition-colors',
                'border-accent text-accent' => $key === $selected,
                'border-transparent text-fg-muted hover:text-fg-soft' => $key !== $selected,
            ])
        >
            <img src="{{ $console->icon }}" alt="" class="size-4 object-contain" />
            {{ $console->name }}
        </button>
    @endforeach
</div>
