@props([
    'title' => null,
    'hint' => null,
    'variant' => 'block',
])

{{-- What stands in for a list with nothing in it. Block is a page's or a
     tab's, dashed and roomy, with room for the button that fills it; inline
     is a line inside a panel or a modal that is already framed. --}}
@if ($variant === 'inline')
    <p {{ $attributes->class('px-4 py-8 text-center text-sm text-fg-faint') }}>
        @if ($slot->isNotEmpty())
            {{ $slot }}
        @else
            {{ $title }}
        @endif
    </p>
@else
    <div {{ $attributes->class('rounded-xl border border-dashed border-line-input px-6 py-10 text-center') }}>
        @if ($title !== null)
            <p class="text-sm text-fg-soft">{{ $title }}</p>
        @endif

        @if ($hint !== null)
            <p class="mt-1 text-sm text-fg-faint">{{ $hint }}</p>
        @endif

        @if ($slot->isNotEmpty())
            <div class="mt-4">{{ $slot }}</div>
        @endif
    </div>
@endif
