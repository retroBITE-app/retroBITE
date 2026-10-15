@props([
    'icon' => null,
    'iconTrailing' => null,
    'type' => 'submit',
])

{{--
    The one large amber button a sign-in or setup form ends on. Outlined and
    tinted rather than filled, as the design fills nothing solid.
--}}
<button
    type="{{ $type }}"
    {{ $attributes->class('mt-1 flex cursor-pointer items-center justify-center gap-2 rounded-lg border border-accent-tint/55 bg-accent-tint/10 px-4 py-3 text-base text-accent transition-colors hover:bg-accent-tint/18 focus-ring') }}
>
    @if ($icon !== null)
        <flux:icon :name="$icon" variant="micro" />
    @endif

    {{ $slot }}

    @if ($iconTrailing !== null)
        <flux:icon :name="$iconTrailing" variant="micro" />
    @endif
</button>
