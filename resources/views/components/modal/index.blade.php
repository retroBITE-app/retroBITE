@props([
    'name',
    'width' => 'lg',
    'heading' => null,
    'subheading' => null,
    'mono' => false,
    'submit' => null,
    'cancel' => null,
    'event' => null,
    'then' => null,
    'footer' => null,
    'footerStart' => null,
])

{{-- A flux:modal laid out the one way every dialog here is: see
     x-modal.content. With event, a window event of that name opens it, and
     then is a line of JS run after, such as $wire.open($event.detail.id).
     x-data and x-init go to the body, so the footer is inside their scope;
     everything else (wire:close, :dismissible) goes to flux:modal. --}}
@php
    $widths = [
        'md' => 'max-w-md',
        'lg' => 'max-w-lg',
        'xl' => 'max-w-xl',
        '2xl' => 'max-w-2xl',
        '4xl' => 'max-w-4xl',
    ];

    $body = $attributes->only(['x-data', 'x-init']);
    $modal = $attributes->except(['x-data', 'x-init'])->class(['w-full', Arr::get($widths, $width, 'max-w-lg')]);
    $open = '$flux.modal('.Illuminate\Support\Js::from($name).').show()'.(filled($then) ? '; '.$then : '');
@endphp

@if (filled($event))
    <div x-on:{{ $event }}.window="{{ $open }}">
@endif

<flux:modal :name="$name" :attributes="$modal">
    <x-modal.content
        :heading="$heading"
        :subheading="$subheading"
        :mono="$mono"
        :submit="$submit"
        :cancel="$cancel"
        :footer="$footer"
        :footer-start="$footerStart"
        :attributes="$body"
    >{{ $slot }}</x-modal.content>
</flux:modal>

@if (filled($event))
    </div>
@endif
