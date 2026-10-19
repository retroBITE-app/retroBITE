@props([
    'heading' => null,
    'subheading' => null,
    'mono' => false,
    'submit' => null,
    'cancel' => null,
    'footer' => null,
    'footerStart' => null,
])

{{-- What sits inside a modal: heading, body and footer, Cancel first and the
     primary action last. Apart from x-modal so one step of a modal that
     swaps its content (adding a console, choosing its layout) can wear it.
     With submit, the whole of it is the form, so the footer's submit
     button is inside it. --}}
@php
    $cancel ??= __('Cancel');
    $tag = filled($submit) ? 'form' : 'div';

    $hasHeading = $heading instanceof Illuminate\View\ComponentSlot ? $heading->hasActualContent() : filled($heading);
    $hasSubheading = $subheading instanceof Illuminate\View\ComponentSlot ? $subheading->hasActualContent() : filled($subheading);
    $hasFooter = $footer instanceof Illuminate\View\ComponentSlot ? $footer->hasActualContent() : filled($footer);
    $hasFooterStart = $footerStart instanceof Illuminate\View\ComponentSlot ? $footerStart->hasActualContent() : filled($footerStart);
@endphp

<{{ $tag }} @if ($tag === 'form') wire:submit="{{ $submit }}" @endif {{ $attributes->class('flex flex-col gap-5') }}>
    @if ($hasHeading || $hasSubheading)
        <div>
            @if ($hasHeading)
                <flux:heading size="lg">{{ $heading }}</flux:heading>
            @endif

            @if ($hasSubheading && $mono)
                <p class="mt-1 truncate font-mono text-xs text-fg-faint">{{ $subheading }}</p>
            @elseif ($hasSubheading)
                <flux:text class="mt-1">{{ $subheading }}</flux:text>
            @endif
        </div>
    @endif

    {{ $slot }}

    @if ($cancel !== false || $hasFooter || $hasFooterStart)
        <div @class([
            'flex items-center gap-2',
            'justify-between' => $hasFooterStart,
            'justify-end' => ! $hasFooterStart,
        ])>
            @if ($hasFooterStart)
                <div class="flex min-w-0 items-center gap-2">{{ $footerStart }}</div>
            @endif

            <div class="flex items-center gap-2">
                @if ($cancel !== false)
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ $cancel }}</flux:button>
                    </flux:modal.close>
                @endif

                {{ $footer }}
            </div>
        </div>
    @endif
</{{ $tag }}>
