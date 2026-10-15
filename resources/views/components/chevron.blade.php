@props(['open' => 'open'])

{{-- The disclosure arrow, turned over while its section is open. `open` is
     the Alpine expression that says so, read from the surrounding scope. --}}
<flux:icon.chevron-down
    :attributes="$attributes->class('size-3.5 shrink-0 transition-transform duration-200')"
    x-bind:class="({{ $open }}) && '-rotate-180'"
/>
