@props(['state' => 'idle'])

{{-- A dot and a word for something that is up or down: pulsing while it is
     still being asked, glowing when the answer is yes. --}}
<span {{ $attributes->class('inline-flex items-center gap-2') }}>
    <span aria-hidden="true" @class([
        'size-2 shrink-0 rounded-full',
        'animate-pulse bg-fg-faint' => $state === 'pending',
        'bg-accent shadow-glow' => $state === 'ok',
        'bg-danger' => $state === 'error',
        'bg-line-strong' => $state === 'idle',
    ])></span>

    @if ($slot->isNotEmpty())
        <span @class([
            'text-sm',
            'text-fg-dim' => $state === 'pending',
            'text-accent' => $state === 'ok',
            'text-danger' => $state === 'error',
            'text-fg-faint' => $state === 'idle',
        ])>{{ $slot }}</span>
    @endif
</span>
