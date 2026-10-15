@props(['text'])

{{-- A field's or an option's explanation, a hover away rather than a paragraph under it.
     A button so it can take focus and show the same text from the keyboard,
     and a label for a screen reader, which cannot hover. Pass a list for
     several lines; a long one wraps rather than running across the page.
     Attributes, a class included, go to the button. --}}
@php($lines = Illuminate\Support\Arr::wrap($text))

<flux:tooltip position="top">
    <button type="button" aria-label="{{ implode(' ', $lines) }}" {{ $attributes->class('grid shrink-0 cursor-help place-items-center rounded-full text-fg-faint transition-colors hover:text-fg-soft focus-ring') }}>
        <flux:icon.information-circle variant="micro" class="size-4" />
    </button>

    <flux:tooltip.content class="max-w-xs">
        @foreach ($lines as $line)
            <span class="block">{{ $line }}</span>
        @endforeach
    </flux:tooltip.content>
</flux:tooltip>
