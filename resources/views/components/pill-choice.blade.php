@props([
    'options',
    'label',
    'model' => null,
    'value' => null,
    // A Livewire method to call with the value, rather than `$set` on $model.
    'method' => null,
    // An Alpine variable to set, for a choice the server does not hold yet.
    'alpine' => null,
    'size' => 'xs',
    'full' => false,
    // Value → Flux icon name; those options draw the icon alone, labelled.
    'icons' => [],
    'mono' => false,
    // True, or an Alpine expression for a choice locked while work runs.
    'disabled' => false,
])

{{--
    A short choice as a row of pills: the docs viewer's Preview / Markdown pill
    rather than Flux's segmented radio, so every few-way switch in the app
    looks alike. $options maps each value to its label. Livewire sets $model,
    or calls $method; with $alpine set it is Alpine's alone. $full spreads the
    pills to equal widths across the row.
--}}
@php
    $on = 'bg-accent-tint/15 text-accent';
    $off = 'text-fg-dim hover:text-fg';
    $pad = Illuminate\Support\Arr::get(['xs' => 'px-2.75 py-1 text-xs', 'sm' => 'px-3 py-1.5 text-sm'], $size, 'px-2.75 py-1 text-xs');
    $iconPad = Illuminate\Support\Arr::get(['xs' => 'px-2 py-1.25', 'sm' => 'px-2.5 py-1.75'], $size, 'px-2 py-1.25');
@endphp

<div role="radiogroup" aria-label="{{ $label }}" {{ $attributes->class([
    'gap-0.5 rounded-lg border border-line-input p-0.5',
    'grid grid-flow-col auto-cols-fr' => $full,
    'flex shrink-0 flex-wrap items-center' => ! $full,
]) }}>
    @foreach ($options as $option => $text)
        @php
            $option = (string) $option;
            $icon = Illuminate\Support\Arr::get($icons, $option);
            $checked = (string) $value === $option;
            $test = $alpine !== null ? $alpine.' === '.Illuminate\Support\Js::from($option) : null;
        @endphp

        <button
            type="button"
            role="radio"
            @if ($alpine !== null)
                x-on:click="{{ $alpine }} = {{ Illuminate\Support\Js::from($option) }}"
                x-bind:aria-checked="{{ $test }} ? 'true' : 'false'"
                x-bind:class="{{ $test }} ? @js($on) : @js($off)"
            @else
                aria-checked="{{ $checked ? 'true' : 'false' }}"
                @if ($method !== null)
                    wire:click="{{ $method }}('{{ $option }}')"
                @else
                    wire:click="$set('{{ $model }}', '{{ $option }}')"
                @endif
            @endif
            @if (is_string($disabled))
                x-bind:disabled="{{ $disabled }}"
            @elseif ($disabled)
                disabled
            @endif
            @if ($icon !== null)
                aria-label="{{ $text }}"
                title="{{ $text }}"
            @endif
            @class([
                'cursor-pointer rounded-md transition-colors focus-ring disabled:cursor-not-allowed disabled:opacity-50',
                $icon !== null ? 'grid place-items-center '.$iconPad : $pad,
                'font-mono' => $mono,
                $on => $alpine === null && $checked,
                $off => $alpine === null && ! $checked,
            ])
        >
            @if ($icon !== null)
                <flux:icon :name="$icon" class="size-4" />
            @else
                {{ $text }}
            @endif
        </button>
    @endforeach
</div>
