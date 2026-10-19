@props(['rating' => null, 'size' => 'sm'])

{{-- The retroBITE score, filled in its band's colour (App\Support\RatingBand).
     Inline styles because the colour is chosen at runtime, and a class
     Tailwind never saw in the source is one it never generated. sm sits in a
     card's caption row; md beside the score's name on the game page. --}}
@if ($rating !== null)
    <span
        style="background-color: {{ App\Support\RatingBand::color((int) $rating) }}; color: {{ App\Support\RatingBand::ink() }}"
        {{ $attributes->class([
            'shrink-0 rounded-md font-mono font-semibold tabular-nums',
            'px-1.5 py-0.5 text-xs' => $size === 'sm',
            'px-2 py-0.5 text-sm' => $size === 'md',
        ]) }}
    >{{ $rating }}</span>
@endif
