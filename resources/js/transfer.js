/**
 * Transfers: copy a game onto a USB drive, straight from the browser — or
 * hand it to the server, when the destination is a network share.
 *
 * The server decides everything — which files, where, and what the game list
 * says (see App\Transfers). This only reads the drive, fetches, and writes, with
 * the File System Access API. Chrome and Edge have it, and only in a secure
 * context: https, or localhost, which is how this project runs it. See
 * docs/adr/0003-transfers-from-the-browser.md. A share is written by the
 * server (FileTransferJob); this only asks the game page to start it — see
 * docs/adr/0004-one-job-moves-files.md.
 *
 * Nothing on the drive is overwritten or removed, except this game's own entry
 * in the game list: a file already there at the same size is skipped, so an
 * interrupted transfer picks up where it stopped. Each file is written under a
 * temporary name and renamed when complete, so a half-written ROM never sits
 * on the drive under its real name.
 */

const DB = 'retrobite-transfer';

// Files copied at once. A console is thousands of small files, and each spends
// most of its time waiting — on the server's answer, on the drive's directory —
// rather than moving bytes, so a few in flight go several times as fast.
const CONCURRENCY = 4;
const STORE = 'drives';
const DRIVE_KEY = 'drive';

/** A tiny IndexedDB wrapper: Chrome can keep a directory handle there. */
function idb(mode, run) {
    return new Promise((resolve, reject) => {
        const open = indexedDB.open(DB, 1);
        open.onupgradeneeded = () => open.result.createObjectStore(STORE);
        open.onerror = () => reject(open.error);
        open.onsuccess = () => {
            const store = open.result.transaction(STORE, mode).objectStore(STORE);
            const request = run(store);
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        };
    });
}

const rememberDrive = (handle) => idb('readwrite', (store) => store.put(handle, DRIVE_KEY));
const recallDrive = () => idb('readonly', (store) => store.get(DRIVE_KEY)).catch(() => null);

async function hasDirectory(parent, name) {
    try {
        await parent.getDirectoryHandle(name);
        return true;
    } catch {
        return false;
    }
}

/** The directory for a relative path, creating what is missing. */
async function directoryFor(root, parts) {
    let dir = root;
    for (const part of parts) {
        dir = await dir.getDirectoryHandle(part, { create: true });
    }
    return dir;
}

async function existingSize(dir, name) {
    try {
        return (await (await dir.getFileHandle(name)).getFile()).size;
    } catch {
        return null;
    }
}

/**
 * The folders of one run, each looked up and listed once rather than once per
 * file: on a USB drive every lookup is a trip to its directory. Promises are
 * kept, not results, so two copies starting together share one lookup.
 */
function folders(root) {
    const handles = new Map();
    const listings = new Map();

    const handle = (path) => {
        if (!handles.has(path)) {
            handles.set(path, directoryFor(root, path === '' ? [] : path.split('/')));
        }
        return handles.get(path);
    };

    return {
        handle,
        async names(path) {
            if (!listings.has(path)) {
                listings.set(path, (async () => {
                    const names = new Set();
                    for await (const name of (await handle(path)).keys()) {
                        names.add(name);
                    }
                    return names;
                })());
            }
            return listings.get(path);
        },
    };
}

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

/**
 * The modal's state. `targets` maps each target's key to its label and the
 * URLs for this game: { batocera: { label, plan, gamelist } }. `shares` maps
 * each saved destination's id to its label and address.
 */
export default ({ targets, shares }) => ({
    supported: window.isSecureContext && 'showDirectoryPicker' in window,
    targets,
    shares,
    target: Object.keys(targets)[0],
    destination: 'usb', // 'usb', or a share's id
    plan: null,
    drive: null,
    driveName: null,
    status: 'idle', // idle | confirm-root | running | done | error
    message: '',
    written: 0,
    total: 0,
    current: '',
    skipped: 0,

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
            this.plan = await (await this.fetchOk(this.targets[this.target].plan)).json();
        } catch (error) {
            this.plan = null;
            this.status = 'error';
            this.message = error?.message ?? String(error);
        }
    },

    size(bytes) {
        const units = ['B', 'KB', 'MB', 'GB'];
        let value = bytes;
        let unit = 0;
        while (value >= 1024 && unit < units.length - 1) {
            value /= 1024;
            unit++;
        }
        return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
    },

    get percent() {
        return this.total > 0 ? Math.round((this.written / this.total) * 100) : 0;
    },

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

    /**
     * Where Batocera's tree starts on this drive: the chosen folder when it
     * holds roms/, its batocera/ folder when that does, or null when neither —
     * the layout of an external drive is not documented, so it is recognised
     * rather than assumed.
     */
    async rootOf(drive) {
        if (await hasDirectory(drive, 'roms')) {
            return drive;
        }

        if (await hasDirectory(drive, 'batocera')) {
            const batocera = await drive.getDirectoryHandle('batocera');
            if (await hasDirectory(batocera, 'roms')) {
                return batocera;
            }
        }

        return null;
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

        const root = (await this.rootOf(this.drive)) ?? (confirmedRoot ? this.drive : null);

        if (!root) {
            // Neither roms/ nor batocera/roms/: ask before making this the root.
            this.status = 'confirm-root';
            return;
        }

        this.status = 'running';
        this.message = '';
        this.written = 0;
        this.skipped = 0;

        try {
            const plan = await (await this.fetchOk(this.targets[this.target].plan)).json();
            this.plan = plan;
            this.total = plan.bytes;

            await this.copyAll(root, plan.files);

            await this.writeGamelist(root, plan.gamelist);

            this.current = '';
            this.status = 'done';
        } catch (error) {
            this.status = 'error';
            this.message = error?.message ?? String(error);
        }
    },

    /**
     * Every file, CONCURRENCY at a time, from one shared queue. The first
     * failure stops the rest from starting; the ones already under way finish,
     * and then it is thrown, so the modal says what went wrong as before.
     */
    async copyAll(root, files) {
        const queue = [...files];
        const tree = folders(root);
        let failure = null;

        const worker = async () => {
            while (failure === null && queue.length > 0) {
                const file = queue.shift();
                try {
                    await this.copy(tree, file);
                } catch (error) {
                    failure ??= error;
                }
            }
        };

        await Promise.all(Array.from({ length: Math.min(CONCURRENCY, files.length) }, worker));

        if (failure !== null) {
            throw failure;
        }
    },

    async copy(tree, file) {
        const parts = file.destination.split('/');
        const name = parts.pop();
        const folder = parts.join('/');
        const dir = await tree.handle(folder);
        this.current = file.destination;

        // Asked of the drive only when the folder's listing has the name: a
        // file that is not there needs no question, and on a first send that
        // is every one of them.
        if ((await tree.names(folder)).has(name) && (await existingSize(dir, name)) === file.size) {
            this.skipped++;
            this.written += file.size;
            return;
        }

        const response = await this.fetchOk(file.url);
        const partName = `.${name}.part`;
        const handle = await dir.getFileHandle(partName, { create: true });
        const writable = await handle.createWritable();

        const counted = new TransformStream({
            transform: (chunk, controller) => {
                this.written += chunk.byteLength;
                controller.enqueue(chunk);
            },
        });

        await response.body.pipeThrough(counted).pipeTo(writable);

        if (typeof handle.move === 'function') {
            await handle.move(name);
        } else {
            // No rename in this browser: write again under the real name.
            const final = await (await dir.getFileHandle(name, { create: true })).createWritable();
            await final.write(await handle.getFile());
            await final.close();
            await dir.removeEntry(partName);
        }
    },

    async writeGamelist(root, destination) {
        const parts = destination.split('/');
        const name = parts.pop();
        const dir = await directoryFor(root, parts);
        this.current = destination;

        let existing = '';
        try {
            existing = await (await (await dir.getFileHandle(name)).getFile()).text();
        } catch {
            // None yet.
        }

        const response = await fetch(this.targets[this.target].gamelist, {
            method: 'POST',
            headers: { 'Content-Type': 'application/xml', 'X-CSRF-TOKEN': csrf(), Accept: 'application/xml' },
            body: existing,
        });

        if (!response.ok) {
            throw new Error(await response.text());
        }

        const writable = await (await dir.getFileHandle(name, { create: true })).createWritable();
        await writable.write(await response.text());
        await writable.close();
    },

    async fetchOk(url) {
        const response = await fetch(url, { headers: { Accept: '*/*' } });

        if (!response.ok) {
            throw new Error(`${response.status} ${response.statusText} — ${url}`);
        }

        return response;
    },
});
