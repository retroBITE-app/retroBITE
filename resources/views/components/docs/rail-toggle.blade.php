{{--
    Shows or hides the list of documents beside the open one. Inside an
    x-data="docsRail" scope, which owns the state and remembers it.
--}}
<button
    type="button"
    x-on:click="toggleRail()"
    x-bind:aria-pressed="rail"
    x-bind:title="rail ? @js(__('Hide the list')) : @js(__('Show the list'))"
    x-bind:class="rail ? 'border-accent-tint/55 bg-accent-tint/10 text-accent' : 'border-line-input text-fg-dim hover:bg-hover hover:text-fg'"
    aria-label="{{ __('Show or hide the list of documents') }}"
    {{ $attributes->class('grid size-9 shrink-0 cursor-pointer place-items-center rounded-lg border transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep') }}
>
    <flux:icon.view-columns class="size-4" />
</button>
