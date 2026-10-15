@props([
    'total',
    'limit' => 10,
    'summary' => null,
    'notes' => null,
])

{{-- What an action is about to do, before it does it: a line saying how
     many, the first few as rows (the caller slices them to limit), how many
     more there are, and notes on what is left alone and why. --}}
@php
    $more = max(0, (int) $total - (int) $limit);
@endphp

<div {{ $attributes->class('flex flex-col gap-3') }}>
    @if (filled($summary))
        <p class="text-sm text-fg-soft">{{ $summary }}</p>
    @endif

    @if ($total > 0)
        <ul class="flex flex-col divide-y divide-line rounded-lg border border-line bg-sunken">
            {{ $slot }}
        </ul>

        @if ($more > 0)
            <p class="text-xs text-fg-faint">{{ trans_choice('and :count more.|and :count more.', $more, ['count' => $more]) }}</p>
        @endif
    @endif

    @if ($notes instanceof Illuminate\View\ComponentSlot ? $notes->hasActualContent() : filled($notes))
        <ul class="flex flex-col gap-0.5 text-xs text-fg-faint">{{ $notes }}</ul>
    @endif
</div>
