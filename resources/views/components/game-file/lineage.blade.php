@props(['verb', 'name', 'more' => 0, 'when' => null])

{{-- Where a file came from or went: "Converted from X + 2 tracks · 3 days
     ago". The slot trails the line, for the toggle that folds the archived
     sources out beneath the card. --}}
<div {{ $attributes->class('flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-fg-muted') }}>
    <flux:icon.arrow-turn-down-right variant="micro" class="size-3.5 text-fg-faint" />
    <span>{{ $verb }}</span>
    <span class="font-mono break-all text-fg-soft">{{ $name }}</span>

    @if ($more > 0)
        <span>{{ trans_choice('+ :count track|+ :count tracks', $more, ['count' => $more]) }}</span>
    @endif

    @if ($when !== null)
        <span class="text-fg-faint">· {{ $when }}</span>
    @endif

    {{ $slot }}
</div>
