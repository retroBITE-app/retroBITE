@props(['state' => 'off'])

{{-- A checkbox drawn, not an input: it sits inside the button that toggles
     the row, and a nested input would be a second control for one action.
     'some' is the select-all box with part of the list picked. --}}
<span
    aria-hidden="true"
    @class([
        'grid size-4 shrink-0 place-items-center rounded border transition-colors',
        'border-accent bg-accent-tint/20 text-accent' => $state !== 'off',
        'border-line-bright' => $state === 'off',
    ])
>
    @if ($state === 'on')
        <flux:icon.check variant="micro" class="size-3" />
    @elseif ($state === 'some')
        <flux:icon.minus variant="micro" class="size-3" />
    @endif
</span>
