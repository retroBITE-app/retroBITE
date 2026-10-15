@props([
    'tone' => 'neutral',
    'icon' => null,
    'busy' => false,
    'count' => null,
    'size' => 'md',
])

{{-- A banner over a list or inside a modal: something to know, something
     wrong, or something running. Busy leads with the turning arrow, the one
     place it stands for waiting; a count, such as files sent so far, sits at
     the far end. The note is the small print under the message. Small is
     for a narrow column such as the sidebar. --}}
<div {{ $attributes->class([
    'flex items-start border',
    'gap-2.5 rounded-lg px-4 py-3 text-sm' => $size !== 'sm',
    'gap-1.5 rounded-md px-2 py-1.5 text-xs' => $size === 'sm',
    'border-line-input bg-sunken text-fg-soft' => $tone === 'neutral',
    'border-accent-tint/55 bg-accent-tint/10 text-accent' => $tone === 'accent',
    'border-warn/40 bg-warn/10 text-warn' => $tone === 'warn',
    'border-danger/40 bg-danger/10 text-danger' => $tone === 'danger',
]) }}>
    @if ($busy)
        <flux:icon.arrow-path @class(['shrink-0 animate-spin', 'mt-0.5 size-4' => $size !== 'sm', 'size-3.5' => $size === 'sm']) />
    @elseif ($icon)
        <flux:icon :icon="$icon" variant="micro" @class(['shrink-0', 'mt-0.5 size-4' => $size !== 'sm', 'size-3.5' => $size === 'sm']) />
    @endif

    <div class="min-w-0 flex-1">
        {{ $slot }}

        @isset($note)
            <p {{ $note->attributes->class('mt-1 text-xs opacity-80') }}>{{ $note }}</p>
        @endisset
    </div>

    @if ($count !== null)
        <span class="ml-auto shrink-0 font-mono text-xs">{{ $count }}</span>
    @endif
</div>
