@props(['consoles', 'selected'])

{{-- One tab per console, for a page using App\Concerns\PicksConsoleTab:
     each calls the page's selectConsole(). The app's own tab strip, with the
     console's icon on each tab. --}}
<x-tabs {{ $attributes }}>
    @foreach ($consoles as $key => $console)
        <x-tabs.item
            wire:key="tab-{{ $key }}"
            wire:click="selectConsole('{{ $key }}')"
            :active="$key === $selected"
            :image="$console->icon"
        >{{ $console->name }}</x-tabs.item>
    @endforeach
</x-tabs>
