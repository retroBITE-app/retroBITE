/**
 * The keyboard for the Ctrl+K box (livewire/global-search): open it, move
 * through what it found, and go to one.
 *
 * The results are Livewire's and re-render as the term changes; this only
 * keeps which of them is lit. Each row is a wire:navigate link already, so
 * Enter clicks it rather than knowing where it leads.
 */
const MODAL = 'global-search';

export default () => ({
    active: 0,

    open() {
        this.active = 0;
        this.$flux.modal(MODAL).show();

        // The list is not in the page until the box is first opened; see
        // $ready in livewire/global-search.
        if (! this.$wire.ready) {
            this.$wire.load();
        }

        // The input carries autofocus, which the dialog honours as it opens;
        // asked again once it has, in case the dialog took focus itself. Found
        // by selector: inside flux:modal it is out of reach of this $refs.
        requestAnimationFrame(() => {
            requestAnimationFrame(() => this.input()?.focus());
        });
    },

    input() {
        return this.$root.querySelector('[data-search-input]');
    },

    close() {
        this.$flux.modal(MODAL).close();
    },

    /** Typing changes the list; the first row is the one to land on. */
    reset() {
        this.active = 0;
    },

    items() {
        return [...this.$root.querySelectorAll('[data-search-item]')];
    },

    move(step) {
        const items = this.items();

        if (items.length === 0) {
            return;
        }

        this.active = (this.active + step + items.length) % items.length;
        items[this.active].scrollIntoView({ block: 'nearest' });
    },

    choose() {
        this.items()[this.active]?.click();
    },
});
