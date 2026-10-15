@props([
    'label',
    'info' => null,
    'name' => null,
    'description' => null,
])

{{-- A flux:field with its label, the explanation a hover away beside it
     (x-info), a line of description and the error for name, so a form
     states each of those once. The slot is the control. --}}
<flux:field {{ $attributes }}>
    @if (filled($info))
        <div class="flex items-center gap-2">
            <flux:label>{{ $label }}</flux:label>
            <x-info :text="$info" />
        </div>
    @else
        <flux:label>{{ $label }}</flux:label>
    @endif

    @if (filled($description))
        <flux:description>{{ $description }}</flux:description>
    @endif

    {{ $slot }}

    @if (filled($name))
        <flux:error :name="$name" />
    @endif
</flux:field>
