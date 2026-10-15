@props([
    'label',
    'collapsible' => true,
    // Nothing to unfold yet: the heading stays, the toggle and chevron go.
    'disabled' => false,
    // Read in place of the label, for a toggle whose label alone does not say what opens.
    'toggleLabel' => null,
])

{{-- One reading in the sidebar footer: a label and its figure on one line,
     `summary` (the bar, a warning) always under it, the default slot a fold
     away. `open` is the caller's Alpine state, usually `$persist`ed in the
     x-data passed to this component.

     Plain x-show rather than x-collapse: these re-render on a signal or a
     timer, and morphing a subtree whose inline height the collapse plugin
     owns is a flicker at exactly the moment somebody is watching the
     figures move. --}}
@php($tag = $collapsible ? 'button' : 'div')

<div {{ $attributes }}>
    <{{ $tag }}
        @if ($collapsible)
            type="button"
            x-on:click="open = ! open"
            x-bind:aria-expanded="open"
            @disabled($disabled)
            @if ($toggleLabel !== null) aria-label="{{ $toggleLabel }}" @endif
        @endif
        @class([
            'flex items-baseline justify-between gap-2 text-fg-muted',
            'w-full cursor-pointer transition-colors not-disabled:hover:text-fg-soft disabled:cursor-default focus-ring' => $collapsible,
        ])
    >
        {{-- `kicker` on the label only: on the row its tracking also
             stretched the figure, which then wrapped. --}}
        <span class="kicker">{{ $label }}</span>

        <span class="flex items-baseline gap-1.5 font-mono text-xs whitespace-nowrap">
            {{ $figure ?? '' }}

            @if ($collapsible && ! $disabled)
                <x-chevron class="self-center text-fg-faint" />
            @endif
        </span>
    </{{ $tag }}>

    {{ $summary ?? '' }}

    @if ($collapsible && $slot->isNotEmpty())
        <div x-show="open" x-cloak class="mt-2.5 flex flex-col gap-2">
            {{ $slot }}
        </div>
    @endif
</div>
