@props(['state'])

@php
    use App\Enums\DecryptState;
@endphp

{{-- A PS3 image's place on the way to playing: App\Enums\DecryptState.
     Warn for the one that will not start, the accent for the one that is
     ready to go further, quiet once there is nothing left to do. --}}
<span
    title="{{ $state->hint() }}"
    {{ $attributes->class([
        'inline-flex shrink-0 items-center gap-1 rounded-md border px-1.5 py-0.5 font-mono text-[10px] tracking-kicker uppercase',
        'border-warn/50 text-warn' => $state === DecryptState::NeedsKey,
        'border-accent-tint/55 text-accent' => $state === DecryptState::Ready,
        'border-line-strong text-fg-muted' => $state === DecryptState::Decrypted,
        'border-dashed border-line-strong text-fg-faint' => $state === DecryptState::Unchecked,
        'animate-pulse border-accent-tint/55 text-accent' => $state === DecryptState::Decrypting,
    ]) }}
>
    @if ($state === DecryptState::Decrypted)
        <flux:icon.lock-open variant="micro" class="size-3" />
    @elseif ($state === DecryptState::Decrypting)
        <flux:icon.arrow-path variant="micro" class="size-3" />
    @elseif ($state !== DecryptState::Unchecked)
        <flux:icon.lock-closed variant="micro" class="size-3" />
    @endif
    {{ $state->label() }}
</span>
