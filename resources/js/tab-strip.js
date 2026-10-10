/**
 * <x-tabs>: the tab strip, scrolling sideways on a narrow screen: its current tab, the
 * one carrying aria-current, scrolled into the middle of the strip. On load —
 * after a refresh, a save, a wire:navigate — and whenever another tab becomes
 * the current one in place. Only the strip scrolls, never the page. Its
 * scrollbar shows while it is hovered or scrolled and fades out after.
 */

// How long the scrollbar stays after the last scroll or hover.
const IDLE_AFTER_MS = 2000;

export default function tabStrip() {
    return {
        observer: null,

        timer: null,

        init() {
            this.reveal(false);

            this.observer = new MutationObserver(() => this.reveal(true));
            this.observer.observe(this.$el, { subtree: true, attributes: true, attributeFilter: ['aria-current'] });

            const wake = () => this.wake();
            this.$el.addEventListener('scroll', wake, { passive: true });
            this.$el.addEventListener('pointerenter', wake);
            this.$el.addEventListener('pointermove', wake, { passive: true });

            // Shown at first, a hint that the strip scrolls, then out of the way.
            this.wake();
        },

        destroy() {
            this.observer?.disconnect();
            clearTimeout(this.timer);
        },

        /** Show the scrollbar, and hide it again once things are still. */
        wake() {
            this.$el.classList.remove('tab-strip-idle');
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.idle(), IDLE_AFTER_MS);
        },

        /** The strip at rest: its scrollbar out of the way of the underline. */
        idle() {
            this.$el.classList.add('tab-strip-idle');
        },

        /** Centre the current tab in the strip, where it can be. */
        reveal(smooth) {
            const tab = this.$el.querySelector('[aria-current]');

            if (!tab) {
                return;
            }

            const strip = this.$el.getBoundingClientRect();
            const box = tab.getBoundingClientRect();
            const left = this.$el.scrollLeft + box.left - strip.left - (strip.width - box.width) / 2;

            this.$el.scrollTo({ left: Math.max(0, left), behavior: smooth ? 'smooth' : 'auto' });
        },
    };
}
