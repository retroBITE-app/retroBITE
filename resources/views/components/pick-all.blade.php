@props([
    'label',
    // As x-check: 'on', 'off' or 'some'.
    'state' => 'off',
])

{{--
    The select-all box at the head of a pickable list. Drawn with x-check like
    the rows under it; the label says what a click does, since the box alone
    cannot.
--}}
<button
    type="button"
    aria-label="{{ $label }}"
    title="{{ $label }}"
    {{ $attributes->class('shrink-0 cursor-pointer rounded focus-ring disabled:cursor-not-allowed disabled:opacity-50') }}
>
    <x-check :state="$state" />
</button>
