{{--
    Rendered markdown, plus a lightbox over its figures.

    The images are read out of the DOM rather than passed in from PHP: the HTML
    is rendered server-side and printed unescaped, so the nodes themselves are
    the only list guaranteed to match what is on screen.
--}}
<div
    x-data="{
        shots: [],
        index: null,
        figures() {
            return Array.from($root.querySelectorAll('[data-doc-body] img'));
        },
        openFrom(event) {
            if (! (event.target instanceof HTMLImageElement)) return;

            const position = this.figures().indexOf(event.target);
            if (position === -1) return;

            this.shots = this.figures().map(img => ({ src: img.currentSrc || img.src, alt: img.alt || '' }));
            this.index = position;
        },
        step(by) {
            if (this.index === null || this.shots.length < 2) return;

            this.index = (this.index + by + this.shots.length) % this.shots.length;
        },
        close() { this.index = null },
    }"
    x-on:keydown.escape.window="close()"
    x-on:keydown.arrow-left.window="step(-1)"
    x-on:keydown.arrow-right.window="step(1)"
>
    <div data-doc-body x-on:click="openFrom($event)">{!! $this->html !!}</div>

    <template x-teleport="body">
        <div
            x-show="index !== null"
            x-cloak
            x-transition.opacity
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
                    x-bind:alt="index === null ? '' : shots[index].alt"
                    class="max-h-[80vh] max-w-[90vw] rounded-xl border border-line object-contain"
                />

                <figcaption class="text-center font-mono text-xs tracking-kicker text-fg-faint uppercase">
                    <span x-show="shots.length > 1" x-text="(index + 1) + ' / ' + shots.length"></span>
                    <span x-show="shots.length > 1 && (index === null ? '' : shots[index].alt)">·</span>
                    <span x-text="index === null ? '' : shots[index].alt"></span>
                </figcaption>
            </figure>
        </div>
    </template>
</div>
