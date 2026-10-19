@props(['state'])

@php
    use App\Enums\DecryptState;
@endphp

{{-- A PS3 image's place on the way to playing: App\Enums\DecryptState,
     whose tone() picks the colour — warn for the one that will not start,
     the accent for the one that is ready to go further, quiet once there is
     nothing left to do. --}}
<x-badge
    :tone="$state->tone()"
    :dashed="$state->dashed()"
    :tip="$state->hint()"
    :attributes="$attributes->class(['animate-pulse' => $state === DecryptState::Decrypting])"
>
    <x-decrypt.icon :state="$state" />
    {{ $state->label() }}
</x-badge>
