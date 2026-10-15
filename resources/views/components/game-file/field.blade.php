@props(['label', 'copy' => null])

{{-- One line of a file card's data block: a label, then its value in
     mono, with a copy button when it is a checksum or key to hand on. --}}
<dt class="kicker text-fg-faint">{{ $label }}</dt>
<dd {{ $attributes->class('flex min-w-0 items-center gap-1.5 font-mono text-xs') }}>
    <span @class(['min-w-0 break-all', 'select-all' => $copy !== null])>{{ $slot }}</span>

    @if ($copy !== null)
        <x-copy-button :text="$copy" icon class="-my-1" />
    @endif
</dd>
