@props(['hint'])

{{-- A button with a line under it on what pressing it does, for the actions a
     label alone cannot explain: the dear ones, and those that only queue work. --}}
<div {{ $attributes }}>
    {{ $slot }}

    <p class="mt-1.5 text-xs text-fg-faint">{{ $hint }}</p>
</div>
