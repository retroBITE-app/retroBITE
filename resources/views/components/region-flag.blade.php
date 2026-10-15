@props([
    'icon' => null,
    'label' => null,
    'code' => null,
    'size' => 'sm',
])

{{-- A region as its flag, or as its code in mono where no picture depicts
     it, named on hover either way. The width follows the height, since
     region flags are not all one shape. Nothing at all without either. --}}
@if (filled($icon))
    <img
        src="{{ $icon }}"
        alt="{{ $label }}"
        @if (filled($label)) title="{{ $label }}" @endif
        {{ $attributes->class([
            'w-auto shrink-0 border border-line-input',
            'h-4' => $size !== 'md',
            'h-4.5' => $size === 'md',
        ]) }}
    />
@elseif (filled($code))
    <span
        @if (filled($label)) title="{{ $label }}" @endif
        {{ $attributes->class('shrink-0 font-mono text-xs text-fg-faint uppercase') }}
    >{{ $code }}</span>
@endif
