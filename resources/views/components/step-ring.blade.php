@props([
    'number' => null,
    // `current` is amber, `done` shows a tick instead of the number, `todo` waits.
    'state' => 'current',
])

{{--
    The small numbered ring of a step: the onboarding rail's and a settings
    form's numbered steps, so the two read as the same kind of thing.
--}}
<span
    {{ $attributes->class([
        'grid size-5 shrink-0 place-items-center rounded-full border font-mono text-[10px]',
        'border-accent-tint/55 bg-accent-tint/15 text-accent' => $state === 'current',
        'border-line-bright text-fg-muted' => $state === 'done',
        'border-line-bright text-fg-faint' => $state === 'todo',
    ]) }}
>
    @if ($state === 'done')
        <flux:icon.check variant="micro" class="size-3" />
    @else
        {{ $number }}
    @endif
</span>
