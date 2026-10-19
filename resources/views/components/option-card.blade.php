@props([
    'label',
    'active' => false,
    'disabled' => false,
    'description' => null,
    // A leading radio dot, for a list where exactly one is picked.
    'radio' => false,
    // The description in mono, for a tool or a format rather than a sentence.
    'mono' => false,
])

{{--
    One option of a stacked one-of-several choice: a title, a line on what it
    does, amber when picked. A button rather than a radio input so a Livewire
    click picks it; with $radio it says so to a screen reader and draws the
    dot. `meta` is an extra line under the description (give it its colour on
    the slot), `badge` sits beside the title, `trailing` on the right.
--}}
<button
    type="button"
    @if ($radio)
        role="radio"
        aria-checked="{{ $active ? 'true' : 'false' }}"
    @else
        aria-pressed="{{ $active ? 'true' : 'false' }}"
    @endif
    @disabled($disabled)
    {{ $attributes->class([
        'flex w-full items-center gap-3 rounded-lg border px-3.5 py-2.5 text-left transition-colors focus-ring',
        'cursor-pointer border-accent-tint/55 bg-accent-tint/10' => $active && ! $disabled,
        'cursor-pointer border-line-input hover:bg-hover' => ! $active && ! $disabled,
        'cursor-not-allowed border-line opacity-50' => $disabled,
    ]) }}
>
    @if ($radio)
        <span aria-hidden="true" @class([
            'grid size-4 shrink-0 place-items-center rounded-full border',
            'border-accent' => $active,
            'border-line-bright' => ! $active,
        ])>
            @if ($active)
                <span class="size-2 rounded-full bg-accent"></span>
            @endif
        </span>
    @endif

    <span class="min-w-0 flex-1">
        <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
            <span @class(['text-sm', 'text-accent' => $active, 'text-fg-soft' => ! $active])>{{ $label }}</span>
            {{ $badge ?? '' }}
        </span>

        @if ($description !== null)
            <span @class(['mt-0.5 block text-xs text-fg-faint', 'font-mono' => $mono])>{{ $description }}</span>
        @endif

        @isset($meta)
            <span {{ $meta->attributes->class('mt-0.5 block text-xs') }}>{{ $meta }}</span>
        @endisset
    </span>

    @isset($trailing)
        <span {{ $trailing->attributes->class('shrink-0') }}>{{ $trailing }}</span>
    @endisset
</button>
