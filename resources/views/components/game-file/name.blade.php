@props(['filename'])

{{-- A file card's title: the name in the page's face, the extension
     dimmed beside it, since the Format badge already says what it is. --}}
<span {{ $attributes->class('wrap-break-word') }}>
    @if (Str::contains($filename, '.'))
        {{ Str::beforeLast($filename, '.') }}<span class="font-normal text-fg-faint">.{{ Str::afterLast($filename, '.') }}</span>
    @else
        {{ $filename }}
    @endif
</span>
