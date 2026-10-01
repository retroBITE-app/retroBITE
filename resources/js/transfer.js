/**
 * Transfers: copy a game onto a USB drive, straight from the browser — or
 * hand it to the server, when the destination is a network share.
 *
 * The server decides everything — which files, where, and what the game list
 * says (see App\Transfers). A drive is written with the File System Access
 * API, which Chrome and Edge have, and only in a secure context: https, or
 * localhost, which is how this project runs it. See
 * docs/adr/0003-transfers-from-the-browser.md. The copying itself is
 * usb-transfers.js's, for the whole tab. A share is written by the server
 * (FileTransferJob); this only asks the game page to start it — see
 * docs/adr/0004-one-job-moves-files.md.
 */

import { fetchOk, recallDrive, rememberDrive, rootOf, size } from './usb-transfers';

/**
 * What the modal was last left on, in this browser: the destination, the
 * target and the region order per console — not every target plays every
 * console, and its order starts from the console's own — and the artwork per
 * target. The drive is remembered beside the queue, in IndexedDB.
 */
const CHOICES_KEY = 'retrobite-send-to';

function recallChoices() {
    try {
        return JSON.parse(localStorage.getItem(CHOICES_KEY) ?? '{}') ?? {};
    } catch {
        return {};
    }
}

function rememberChoices(choices) {
    try {
        localStorage.setItem(CHOICES_KEY, JSON.stringify(choices));
    } catch {
        // A private window or blocked storage: the modal starts afresh next time.
    }
}

/**
 * The modal's state. `targets` maps each target's key to its label, what to
 * tell the user, the folders that mark its root, the question to ask when
 * none is there, the artwork it can be sent, and the URLs for this game:
 * { batocera: { label, hint, root, confirm, artwork, plan, gamelist } }.
 * `target` is the one to start on, unless another was chosen for `console`
 * last time. `shares` maps each saved destination's id to its label and
 * address. `label` is what the tray calls this transfer.
 *
 * Choosing and checking is done here; the copying is $store.usb's, so it goes
 * on when the modal is closed or the page left.
 */
export default ({ targets, target, shares, regions, console: consoleKey, label }) => ({
    supported: window.isSecureContext && 'showDirectoryPicker' in window,
    targets,
    shares,
    target: target ?? Object.keys(targets)[0],
    destination: 'usb', // 'usb', or a share's id
    defaultRegions: regions, // the console's order, or the library's
    regions: [...regions], // the order this send tries regions in
    artwork: [], // the target's artwork slots that go
    plan: null,
    planned: 0, // which loadPlan() is the latest, so an earlier answer cannot win
    drive: null,
    driveName: null,
    status: 'idle', // idle | confirm-root | sent | error
    message: '',

    async init() {
        const choices = recallChoices();

        if (choices.destination in this.shares) {
            this.destination = choices.destination;
        }

        const remembered = consoleKey ? choices.targets?.[consoleKey] : undefined;

        if (remembered !== undefined && remembered in this.targets) {
            this.target = remembered;
        }

        this.artwork = this.recalledArtwork(this.target);

        const sorted = consoleKey ? choices.regions?.[consoleKey] : undefined;

        if (Array.isArray(sorted) && sorted.length > 0) {
            this.regions = sorted;
        }

        this.$watch('destination', () => this.remember());
        this.$watch('target', (key) => {
            this.artwork = this.recalledArtwork(key);
            this.remember();
            this.loadPlan();
        });
        this.$watch('regions', () => {
            this.remember();
            this.loadPlan();
        });

        await this.loadPlan();

        if (!this.supported) {
            return;
        }

        this.drive = await recallDrive();
        this.driveName = this.drive?.name ?? null;
    },

    /** The slots chosen for a target last time, still offered; every one when none were. */
    recalledArtwork(key) {
        const offered = Object.keys(this.targets[key]?.artwork ?? {});
        const chosen = recallChoices().artwork?.[key];

        return Array.isArray(chosen) ? chosen.filter((slot) => offered.includes(slot)) : offered;
    },

    toggleArtwork(slot) {
        this.artwork = this.artwork.includes(slot) ? this.artwork.filter((s) => s !== slot) : [...this.artwork, slot];
        this.remember();
        this.loadPlan();
    },

    remember() {
        const choices = recallChoices();

        rememberChoices({
            ...choices,
            destination: this.destination,
            targets: consoleKey ? { ...(choices.targets ?? {}), [consoleKey]: this.target } : (choices.targets ?? {}),
            artwork: { ...(choices.artwork ?? {}), [this.target]: this.artwork },
            regions: consoleKey ? { ...(choices.regions ?? {}), [consoleKey]: this.regions } : (choices.regions ?? {}),
        });
    },

    /** Whether the order is still the console's, which needs saying to nobody. */
    get regionsAreDefault() {
        return this.regions.join(',') === this.defaultRegions.join(',');
    },

    /**
     * The choices as the server takes them (App\Transfers\TransferOptions):
     * nothing for the console's own region order, so changing it in the
     * settings still moves a send, and nothing for every slot, so a slot a
     * target gains later goes without anybody choosing it.
     */
    options() {
        const offered = Object.keys(this.targets[this.target].artwork);
        const every = offered.every((slot) => this.artwork.includes(slot));

        return {
            regions: this.regionsAreDefault ? null : [...this.regions],
            artwork: every ? null : offered.filter((slot) => this.artwork.includes(slot)),
        };
    },

    /** One of the target's URLs, carrying the choices — a queued drive transfer keeps them. */
    withOptions(url) {
        const { regions, artwork } = this.options();
        const withChoices = new URL(url, window.location.href);

        if (regions !== null) {
            withChoices.searchParams.set('regions', regions.join(','));
        }

        if (artwork !== null) {
            withChoices.searchParams.set('artwork', artwork.join(','));
        }

        return withChoices.toString();
    },

    /** What will be written, shown before anything is. */
    async loadPlan() {
        const asked = ++this.planned;

        try {
            const plan = await (await fetchOk(this.withOptions(this.targets[this.target].plan))).json();

            if (asked === this.planned) {
                this.plan = plan;
                this.status = this.status === 'error' ? 'idle' : this.status;
            }
        } catch (error) {
            if (asked !== this.planned) {
                return;
            }

            this.plan = null;
            this.status = 'error';
            this.message = error?.message ?? String(error);
        }
    },

    size,

    async chooseDrive() {
        try {
            this.drive = await window.showDirectoryPicker({ id: 'retrobite-transfer', mode: 'readwrite' });
            this.driveName = this.drive.name;
            await rememberDrive(this.drive);
        } catch {
            // Closed without choosing: nothing to do.
        }
    },

    async permitted() {
        const options = { mode: 'readwrite' };

        if ((await this.drive.queryPermission(options)) === 'granted') {
            return true;
        }

        return (await this.drive.requestPermission(options)) === 'granted';
    },

    async start(confirmedRoot = false) {
        if (this.destination !== 'usb') {
            // The server copies to a share; the game page follows it.
            await this.$wire.sendToShare(Number(this.destination), this.target, this.options());
            return;
        }

        if (!this.drive) {
            await this.chooseDrive();
        }

        if (!this.drive || !(await this.permitted())) {
            return;
        }

        const { root, confirm } = this.targets[this.target];
        const plan = this.withOptions(this.targets[this.target].plan);
        const gamelist = this.withOptions(this.targets[this.target].gamelist);

        if (!confirmedRoot && !(await rootOf(this.drive, root))) {
            // None of the target's roots: ask before making this the root.
            this.status = 'confirm-root';
            return;
        }

        this.$store.usb.enqueue({ label, plan, gamelist, root, confirm, confirmed: confirmedRoot }, this.drive);
        this.status = 'sent';
    },
});
