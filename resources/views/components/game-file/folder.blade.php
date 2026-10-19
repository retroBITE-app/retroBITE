@props(['path' => null])

{{-- The subfolder a file sits in under its console's. Broken anywhere, since
     a path has no spaces to wrap at; nothing at all for the console's root. --}}
@if (filled($path))
    <p {{ $attributes->class('font-mono text-xs break-all text-fg-faint') }}>{{ $path }}</p>
@endif
