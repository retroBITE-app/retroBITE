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
 * The modal's state. `targets` maps each target's key to its label, what to
 * tell the user, the folders that mark its root, and the URLs for this game:
 * { batocera: { label, hint, roots, confirmRoot, plan, gamelist } }. `shares`
 * maps each saved destination's id to its label and address. `label` is what
 * the tray calls this transfer.
 *
 * Choosing and checking is done here; the copying is $store.usb's, so it goes
 * on when the modal is closed or the page left.
 */
export default ({ targets, shares, label }) => ({
    supported: window.isSecureContext && 'showDirectoryPicker' in window,
    targets,
    shares,
    target: Object.keys(targets)[0],
    destination: 'usb', // 'usb', or a share's id
    plan: null,
    drive: null,
    driveName: null,
    status: 'idle', // idle | confirm-root | sent | error
    message: '',

    async init() {
        this.$watch('target', () => this.loadPlan());
        await this.loadPlan();

        if (!this.supported) {
            return;
        }

        this.drive = await recallDrive();
        this.driveName = this.drive?.name ?? null;
    },

    /** What will be written, shown before anything is. */
    async loadPlan() {
        try {
            this.plan = await (await fetchOk(this.targets[this.target].plan)).json();
        } catch (error) {
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
            await this.$wire.sendToShare(Number(this.destination), this.target);
            return;
        }

        if (!this.drive) {
            await this.chooseDrive();
        }

        if (!this.drive || !(await this.permitted())) {
            return;
        }

        const { roots, confirmRoot, plan, gamelist } = this.targets[this.target];

        if (!confirmedRoot && !(await rootOf(this.drive, roots))) {
            // None of the target's roots: ask before making this the root.
            this.status = 'confirm-root';
            return;
        }

        this.$store.usb.enqueue({ label, plan, gamelist, roots, confirmRoot, confirmed: confirmedRoot }, this.drive);
        this.status = 'sent';
    },
});
