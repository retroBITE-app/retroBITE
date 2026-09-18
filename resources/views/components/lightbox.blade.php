{{--
    A full-screen viewer over a set of images already on the page.

    Two ways in, because the callers know different things. A docs page prints
    markdown unescaped, so only the DOM knows what ended up on screen: leave
    `images` null and the nodes matching `selector` are the set. A page that
    renders its own images knows more than the markup can carry — which row an
    image is, how to caption it — so it passes `images` and marks every image
    that should open the viewer with `data-lightbox="<key>"`.

    Do not nest two of these: the click bubbles to both roots and both open.
--}}
@props([
    // The explicit set, `[{key, src, caption}, …]`; null scans the DOM instead.
    'images' => null,
    // Which nodes open the viewer. With `images` null this is also the set.
    'selector' => 'img',
])

<div
    {{ $attributes }}
    {{-- Not @js(): that emits a JS expression, and this is a payload read back
         out of the DOM. Read on every open rather than captured into x-data,
         because Livewire morphs this markup while artwork arrives and x-data is
         evaluated exactly once. --}}
    @if ($images !== null) data-lightbox-images="{{ json_encode($images) }}" @endif
    x-data="{
        shots: [],
        index: null,
        given() {
            const raw = $root.dataset.lightboxImages;

            return raw === undefined ? null : JSON.parse(raw);
        },
        triggers() {
            return Array.from($root.querySelectorAll(@js($selector)));
        },
        openFrom(event) {
            const trigger = event.target.closest(@js($selector));
            if (! trigger) return;

            const given = this.given();

            if (given === null) {
                const nodes = this.triggers();
                const at = nodes.indexOf(trigger);
                if (at === -1) return;

                this.shots = nodes.map(node => ({ src: node.currentSrc || node.src, caption: node.alt || '' }));
                this.index = at;

                return;
            }

            const at = given.findIndex(shot => shot.key === trigger.dataset.lightbox);
            if (at === -1) return;

            this.shots = given;
            this.index = at;
        },
        step(by) {
            if (this.index === null || this.shots.length < 2) return;

            this.index = (this.index + by + this.shots.length) % this.shots.length;
        },
        close() { this.index = null },
    }"
    x-on:click="openFrom($event)"
    x-on:keydown.escape.window="close()"
    {{-- Gated so the page behind does not scroll while the overlay is over it. --}}
    x-on:keydown.arrow-left.window="if (index !== null) { $event.preventDefault(); step(-1) }"
    x-on:keydown.arrow-right.window="if (index !== null) { $event.preventDefault(); step(1) }"
>
    {{ $slot }}

    <template x-teleport="body">
        <div
            x-show="index !== null"
            x-cloak
            x-transition.opacity
            {{-- Focus in, trapped while open, restored on close, page locked —
                 all four. Alpine's Focus plugin ships inside Livewire's bundle. --}}
            x-trap.noscroll="index !== null"
            x-on:click.self="close()"
            class="fixed inset-0 z-50 grid place-items-center bg-scrim/95 p-6 backdrop-blur-sm"
            role="dialog"
            aria-modal="true"
        >
            <button
                type="button"
                x-on:click="close()"
                aria-label="{{ __('Close') }}"
                class="absolute top-4 right-4 cursor-pointer rounded-lg border border-line-bright bg-surface/80 p-2 text-fg-dim transition-colors hover:text-fg"
            >
                <flux:icon.x-mark class="size-5" />
            </button>

            <template x-if="shots.length > 1">
                <button
                    type="button"
                    x-on:click="step(-1)"
                    aria-label="{{ __('Previous image') }}"
                    class="absolute left-4 cursor-pointer rounded-lg border border-line-bright bg-surface/80 p-2 text-fg-dim transition-colors hover:text-fg"
                >
                    <flux:icon.chevron-left class="size-6" />
                </button>
            </template>

            <template x-if="shots.length > 1">
                <button
                    type="button"
                    x-on:click="step(1)"
                    aria-label="{{ __('Next image') }}"
                    class="absolute right-4 cursor-pointer rounded-lg border border-line-bright bg-surface/80 p-2 text-fg-dim transition-colors hover:text-fg"
                >
                    <flux:icon.chevron-right class="size-6" />
                </button>
            </template>

            <figure class="grid max-h-full max-w-full justify-items-center gap-3" x-on:click.stop>
                <img
                    x-bind:src="index === null ? '' : shots[index].src"
                    x-bind:alt="index === null ? '' : shots[index].caption"
                    class="max-h-[80vh] max-w-[90vw] rounded-xl border border-line object-contain"
                />

                <figcaption class="text-center font-mono text-xs tracking-kicker text-fg-faint uppercase">
                    <span x-show="shots.length > 1" x-text="(index + 1) + ' / ' + shots.length"></span>
                    <span x-show="shots.length > 1 && (index === null ? '' : shots[index].caption)">·</span>
                    <span x-text="index === null ? '' : shots[index].caption"></span>
                </figcaption>
            </figure>
        </div>
    </template>
</div>
