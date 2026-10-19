@props([
    'label',
    'icon' => 'arrow-up-tray',
    'hint' => null,
    'accept' => null,
    'multiple' => false,
])

{{--
    A dashed zone to click or drop files on. The label is the target, so the
    file input inside it stays visually hidden yet takes focus and drops.
    Binding attributes (wire:model, x-on:change, x-ref, a disabled) go to the
    input; the rest, drag handlers included, to the zone. The slot sits under
    the hint, for a line about what was picked.
--}}
@php
    $inputAttributes = $attributes->whereStartsWith(['wire:model', 'x-on:change', 'x-ref', 'x-bind:disabled', 'disabled']);
@endphp

<label {{ $attributes->whereDoesntStartWith(['wire:model', 'x-on:change', 'x-ref', 'x-bind:disabled', 'disabled'])->class('grid cursor-pointer place-items-center gap-1 rounded-xl border border-dashed border-line-input px-6 py-8 text-center transition-colors hover:bg-hover has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-accent-deep') }}>
    <input
        type="file"
        @if ($accept !== null) accept="{{ $accept }}" @endif
        @if ($multiple) multiple @endif
        {{ $inputAttributes->class('sr-only') }}
    />

    <flux:icon :name="$icon" class="size-6 text-fg-faint" />

    <span class="text-sm text-fg-soft">{{ $label }}</span>

    @if ($hint !== null)
        <span class="font-mono text-xs text-fg-faint">{{ $hint }}</span>
    @endif

    {{ $slot }}
</label>
