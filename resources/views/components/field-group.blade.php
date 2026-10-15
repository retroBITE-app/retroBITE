@props([
    'label',
    'hint' => null,
])

{{-- A kicker over a group of controls that is not one form field: a row
     of chips, a picker, a preview. The hint, when there is one, sits
     between the kicker and what it explains. --}}
<div {{ $attributes }}>
    <p class="kicker mb-2 text-fg-faint">{{ $label }}</p>

    @if (filled($hint))
        <p class="mb-2 text-xs text-fg-faint">{{ $hint }}</p>
    @endif

    {{ $slot }}
</div>
