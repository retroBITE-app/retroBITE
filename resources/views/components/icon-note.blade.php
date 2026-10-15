@props(['icon'])

{{-- One point in a short list of asides, its icon a hint at the subject.
     The caller's <ul> sets the gap and the text colour. --}}
<li {{ $attributes->class('flex gap-2.5') }}>
    <flux:icon :icon="$icon" variant="micro" class="mt-0.5 size-4 shrink-0 text-fg-muted" />
    <span>{{ $slot }}</span>
</li>
