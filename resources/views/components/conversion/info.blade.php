@props(['text'])

{{-- An option's explanation, a hover away rather than a paragraph under it.
     A button so it can take focus and show the same text from the keyboard,
     and a label for a screen reader, which cannot hover. --}}
<flux:tooltip :content="$text" position="top">
    <button type="button" aria-label="{{ $text }}" class="grid shrink-0 cursor-help place-items-center rounded-full text-fg-faint transition-colors hover:text-fg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep">
        <flux:icon.information-circle variant="micro" class="size-4" />
    </button>
</flux:tooltip>
