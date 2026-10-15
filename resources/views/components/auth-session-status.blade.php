@props([
    'status',
])

{{-- A one-off note handed over in the session, such as a password just
     changed: in the accent, which is how this design marks what went well. --}}
@if ($status)
    <x-notice tone="accent" {{ $attributes }}>{{ $status }}</x-notice>
@endif
