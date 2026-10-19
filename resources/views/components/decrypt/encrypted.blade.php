{{-- A game on a shelf with an encrypted disc image: it will not start without
     its key beside it, and Tools → Decrypt is where it stops needing one. The
     list reads the flag off games.index's withExists, never per card. --}}
<x-badge
    tone="warn"
    icon="lock-closed"
    :tip="__('Encrypted. It needs its disc key to start; decrypt it to play without one.')"
    {{ $attributes }}
>{{ __('Encrypted') }}</x-badge>
