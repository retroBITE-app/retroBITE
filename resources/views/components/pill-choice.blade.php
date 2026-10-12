@props(['model', 'value', 'options', 'label'])

{{--
    A short choice as a row of pills, setting a Livewire property on click.
    The docs viewer's Preview / Markdown pill rather than Flux's segmented
    radio, so every few-way switch in the app looks alike. $options maps each
    value to its label.
--}}
<div role="radiogroup" aria-label="{{ $label }}" {{ $attributes->class('flex shrink-0 flex-wrap items-center gap-0.5 rounded-lg border border-line-input p-0.5') }}>
    @foreach ($options as $option => $text)
        <button
            type="button"
            role="radio"
            aria-checked="{{ (string) $value === (string) $option ? 'true' : 'false' }}"
            wire:click="$set('{{ $model }}', '{{ $option }}')"
            @class([
                'cursor-pointer rounded-md px-2.75 py-1 text-xs transition-colors',
                'bg-accent-tint/15 text-accent' => (string) $value === (string) $option,
                'text-fg-dim hover:bg-hover hover:text-fg' => (string) $value !== (string) $option,
            ])
        >{{ $text }}</button>
    @endforeach
</div>
