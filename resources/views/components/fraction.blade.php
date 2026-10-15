@props(['value', 'of', 'unit' => null])

{{-- So many of so many: the count reads first, the whole it is out of
     quieter beside it. --}}
<span {{ $attributes->class('tabular-nums') }}>{{ $value }} <span class="text-fg-faint">/ {{ $of }}@if ($unit !== null) {{ $unit }}@endif</span></span>
