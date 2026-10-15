@props(['state'])

@php
    use App\Enums\DecryptState;
@endphp

{{-- The lock beside a DecryptState: open once decrypted, turning while it
     runs, shut before; none while the image is still unchecked. --}}
@if ($state === DecryptState::Decrypted)
    <flux:icon.lock-open variant="micro" {{ $attributes->class('size-3') }} />
@elseif ($state === DecryptState::Decrypting)
    <flux:icon.arrow-path variant="micro" {{ $attributes->class('size-3') }} />
@elseif ($state !== DecryptState::Unchecked)
    <flux:icon.lock-closed variant="micro" {{ $attributes->class('size-3') }} />
@endif
