@props([
    'kicker' => null,
    'title' => null,
    'icon' => null,
    'description' => null,
])

{{-- One card on a settings screen. Two headings: an icon and a title for
     the card that is the screen's subject, a kicker for one that supports
     it. With neither, just the shell around whatever the slot holds. --}}
<div {{ $attributes->class('rounded-xl border border-line bg-surface p-5') }}>
    @if ($title !== null || $icon !== null)
        <div class="mb-4 flex items-center gap-2.5">
            @if ($icon !== null)
                <flux:icon :icon="$icon" class="size-[17px] shrink-0 text-accent" />
            @endif

            <p class="flex-1 text-sm text-fg-bright">{{ $title }}</p>

            {{ $badge ?? '' }}
            {{ $actions ?? '' }}
        </div>
    @elseif ($kicker !== null)
        {{-- Closer to a description that explains it than to the content
             itself, which needs the room to read as a separate block. --}}
        <div @class(['flex items-center justify-between gap-3', 'mb-1' => $description !== null, 'mb-3' => $description === null])>
            <p class="kicker text-fg-faint">{{ $kicker }}</p>

            {{ $actions ?? '' }}
        </div>
    @endif

    @if ($description !== null)
        <p class="mb-4 text-sm text-fg-soft">{{ $description }}</p>
    @endif

    {{ $slot }}
</div>
