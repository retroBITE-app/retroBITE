@props([
    'name',
    'heading',
    'subheading' => null,
    'model' => 'search',
    'placeholder' => null,
    'empty' => null,
    'hasResults' => true,
    'width' => 'md',
])

{{-- A dialog that is a search box and the rows it finds, each a pick that
     does its own work (wire:click on an x-search-row). The caller says
     whether anything was found, rather than this guessing from markup. --}}
<x-modal :name="$name" :width="$width" :heading="$heading" :subheading="$subheading" :cancel="false" {{ $attributes }}>
    <flux:input
        wire:model.live.debounce.200ms="{{ $model }}"
        icon="magnifying-glass"
        :placeholder="$placeholder ?? __('Search')"
        autofocus
    />

    <div class="max-h-80 overflow-y-auto">
        @if ($hasResults)
            {{ $slot }}
        @elseif (filled($empty))
            <x-empty-state variant="inline" :title="$empty" />
        @endif
    </div>
</x-modal>
