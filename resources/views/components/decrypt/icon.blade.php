@props(['state'])

@php
    use App\Enums\DecryptState;
@endphp

{{-- The lock beside a DecryptState: open once decrypted, turning while it
     runs, shut before; none while nothing is known about the image. --}}
@if ($state === DecryptState::Decrypted)
    <flux:icon.lock-open variant="micro" {{ $attributes->class('size-3') }} />
@elseif ($state === DecryptState::Decrypting)
    <flux:icon.arrow-path variant="micro" {{ $attributes->class('size-3') }} />
@elseif (! $state->dashed())
    <flux:icon.lock-closed variant="micro" {{ $attributes->class('size-3') }} />
@endif
