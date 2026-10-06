@props([
    // A Flux icon name.
    'icon',
    'label',
    // Absolute; shown below the label without its scheme.
    'url',
])

{{-- An outbound link drawn as a bordered row: icon, label over the address, arrow. Opens in a new tab. --}}
<a
    href="{{ $url }}"
    target="_blank"
    rel="noopener noreferrer"
    {{ $attributes->class('flex items-center gap-2.5 rounded-lg border border-line-strong px-2.5 py-2.5 transition-colors hover:border-accent-tint/50 hover:bg-accent-tint/6 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep') }}
>
    <flux:icon :icon="$icon" class="size-4 shrink-0 text-accent" />
    <span class="min-w-0 flex-1">
        <span class="block text-sm text-fg-soft">{{ $label }}</span>
        <span class="mt-0.5 block truncate font-mono text-xs text-fg-dim">{{ Str::after($url, '://') }}</span>
    </span>
    <flux:icon.arrow-up-right variant="micro" class="size-3.5 shrink-0 text-fg-faint" />
</a>
