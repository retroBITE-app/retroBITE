@props([
    'model',
    'label',
    'stored' => false,
    'placeholder' => null,
    // A Livewire method that drops the stored value; no button without one.
    'forget' => null,
    'forgetLabel' => null,
])

{{-- A password or key the server keeps and never sends back. The field stays
     empty once one is stored, so the placeholder is what says it is there,
     and typing replaces it rather than editing it. Attributes go to the input. --}}
<div class="flex flex-col gap-4">
    <flux:input
        wire:model="{{ $model }}"
        type="password"
        :label="$label"
        :placeholder="$stored ? __('Stored — type to replace it') : $placeholder"
        :description="__('Kept encrypted in the database and never shown again once saved.')"
        {{ $attributes }}
    />

    @if ($stored && $forget !== null)
        <div>
            <flux:button size="xs" variant="danger" wire:click="{{ $forget }}" type="button">
                {{ $forgetLabel ?? __('Remove') }}
            </flux:button>
        </div>
    @endif
</div>
