@props([
    'label' => null,
    'value' => null,
    'icon' => null,
])

{{-- Where something will be written, or what will be inserted, shown
     before it is: one mono line, cut short with the whole of it on hover.
     A dash stands in until there is anything to show. --}}
<x-field-group :label="$label ?? __('Writes to')" {{ $attributes }}>
    <p class="flex items-center gap-2 rounded-lg border border-line-input bg-sunken px-3 py-2 font-mono text-xs text-fg-dim" title="{{ $value }}">
        @if (filled($icon))
            <flux:icon :icon="$icon" variant="micro" class="size-3.5 shrink-0 text-fg-faint" />
        @endif

        <span class="min-w-0 truncate">{{ filled($value) ? $value : '—' }}</span>
    </p>
</x-field-group>
