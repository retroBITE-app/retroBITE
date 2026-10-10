/**
 * Which protocols' share lists are open on the dashboard's Network shares
 * panel. All folded at first; whichever somebody opens stays open across a
 * refresh in this browser. Kept per browser: a view preference, not a setting.
 * Storage can be blocked, in which case every list starts folded.
 */
const KEY = 'retrobite-share-rows';

export default () => ({
    open: [],

    init() {
        try {
            const kept = JSON.parse(localStorage.getItem(KEY) ?? '[]');
            this.open = Array.isArray(kept) ? kept : [];
        } catch (e) {
            // Folded, then.
        }
    },

    isOpen(protocol) {
        return this.open.includes(protocol);
    },

    toggle(protocol) {
        this.open = this.isOpen(protocol)
            ? this.open.filter((kept) => kept !== protocol)
            : [...this.open, protocol];

        try {
            localStorage.setItem(KEY, JSON.stringify(this.open));
        } catch (e) {
            // Not kept: the next page starts folded.
        }
    },
});
