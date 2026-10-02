/**
 * Whether the list of documents beside an open one is shown — on the
 * Documents page and on a game's Documents tab alike, as one preference.
 *
 * Kept per browser: a reading preference, not a setting. Storage can be
 * blocked, in which case the list is simply shown.
 */
const KEY = 'retrobite-docs-rail';

export default () => ({
    rail: true,

    init() {
        try {
            this.rail = localStorage.getItem(KEY) !== 'hidden';
        } catch (e) {
            // Shown, then.
        }
    },

    toggleRail() {
        this.rail = !this.rail;

        try {
            localStorage.setItem(KEY, this.rail ? 'shown' : 'hidden');
        } catch (e) {
            // Not kept: the next page shows the list again.
        }
    },
});
