/**
 * A tab strip that scrolls sideways on a narrow screen: its current tab, the
 * one carrying aria-current, scrolled into the middle of the strip. On load —
 * after a refresh, a save, a wire:navigate — and whenever another tab becomes
 * the current one in place. Only the strip scrolls, never the page.
 */
export default function tabStrip() {
    return {
        observer: null,

        init() {
            this.reveal(false);

            this.observer = new MutationObserver(() => this.reveal(true));
            this.observer.observe(this.$el, { subtree: true, attributes: true, attributeFilter: ['aria-current'] });
        },

        destroy() {
            this.observer?.disconnect();
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
