/**
 * Copying onto a USB drive, for the whole tab rather than one page: an Alpine
 * store (`$store.usb`) that holds a queue of transfers and runs them one at a
 * time, whatever page is open. The Send to modal only adds to it; the tray in
 * the layout (components/transfer-tray) shows how it is going.
 *
 * Moving between pages is wire:navigate, which swaps the page and keeps this
 * running. A reload or a closed tab cannot keep it, so what is left of the
 * queue is kept in localStorage and taken up again on the next page: the plan
 * is asked for afresh and every file already on the drive at its size is
 * skipped, so it goes on in effect where it stopped. The browser is asked to
 * warn before a reload while something is being written.
 *
 * Nothing on the drive is overwritten or removed, except the game's own entry
 * in the game list: a file already there at the same size is skipped, a
 * target's own file (OPL's config and art) already there is left as it is,
 * and each file is written under a temporary name and renamed when complete,
 * so a half-written ROM never sits on the drive under its real name. See
 * docs/adr/0003-transfers-from-the-browser.md.
 */

import { estimate, roundedMinutes, sample } from './transfer-eta';

const DB = 'retrobite-transfer';
const STORE = 'drives';
const DRIVE_KEY = 'drive';
const QUEUE_KEY = 'retrobite-usb-transfers';

// Files copied at once. A console is thousands of small files, and each spends
// most of its time waiting — on the server's answer, on the drive's directory —
// rather than moving bytes, so a few in flight go several times as fast.
const CONCURRENCY = 4;

// How often the time left is worked out, and how often what it says may change.
const TICK_MS = 1_000;
const ETA_EVERY_MS = 5_000;

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

export const rememberDrive = (handle) => idb('readwrite', (store) => store.put(handle, DRIVE_KEY));
export const recallDrive = () => idb('readonly', (store) => store.get(DRIVE_KEY)).catch(() => null);

export async function hasDirectory(parent, name) {
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

/**
 * Where the target's tree starts on this drive: the folder holding the first
 * of its markers found — roms/ for Batocera, at the top or under batocera/ —
 * or null when none is, since the layout of an external drive is recognised
 * rather than assumed. A target with no markers, such as OPL, starts at the
 * chosen folder itself.
 */
export async function rootOf(drive, markers) {
    if (markers.length === 0) {
        return drive;
    }

    for (const root of markers) {
        const parts = root.split('/');
        let parent = drive;

        for (const part of parts.slice(0, -1)) {
            parent = (await hasDirectory(parent, part)) ? await parent.getDirectoryHandle(part) : null;
            if (!parent) {
                break;
            }
        }

        if (parent && (await hasDirectory(parent, parts[parts.length - 1]))) {
            return parent;
        }
    }

    return null;
}

export function size(bytes) {
    const units = ['B', 'KB', 'MB', 'GB'];
    let value = bytes;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }
    return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
}

export async function fetchOk(url, signal) {
    const response = await fetch(url, { headers: { Accept: '*/*' }, signal });

    if (!response.ok) {
        throw new Error(`${response.status} ${response.statusText} — ${url}`);
    }

    return response;
}

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

/** The queue as it was left, or none: storage can be blocked, or hold junk. */
function savedQueue() {
    try {
        const jobs = JSON.parse(localStorage.getItem(QUEUE_KEY) ?? '[]');
        return Array.isArray(jobs) ? jobs.filter((job) => job?.plan && job?.gamelist && Array.isArray(job?.root)) : [];
    } catch {
        return [];
    }
}

function saveQueue(jobs) {
    try {
        if (jobs.length > 0) {
            localStorage.setItem(QUEUE_KEY, JSON.stringify(jobs));
        } else {
            localStorage.removeItem(QUEUE_KEY);
        }
    } catch {
        // Not kept: a reload loses the queue, as it did before.
    }
}

const permission = { mode: 'readwrite' };

/**
 * The store. A job is { label, plan, gamelist, root, confirm, confirmed }:
 * what to call it, the URLs of its plan and its merged game list, the folders
 * that mark its target's root and what to say when the drive has none, and
 * whether the person agreed to write it to a drive holding none of them.
 */
export default () => ({
    jobs: [], // the one running first, then those waiting
    status: 'idle', // idle | running | interrupted | done | error | stopped
    label: '',
    message: '',
    written: 0,
    total: 0,
    copied: 0, // bytes actually copied, which the time left is worked out from
    skipped: 0,
    current: '',
    left: '',
    words: { underMinute: '', minutes: '', hours: '', wholeHours: '' },
    drive: null,
    samples: [],
    leftAt: 0,
    ticker: null,
    abort: null,

    async init() {
        window.addEventListener('beforeunload', (event) => {
            if (this.status === 'running') {
                event.preventDefault();
                event.returnValue = '';
            }
        });

        const jobs = savedQueue();

        if (jobs.length === 0 || !('showDirectoryPicker' in window)) {
            return;
        }

        this.jobs = jobs;
        this.label = jobs[0].label;
        this.status = 'interrupted';
        this.drive = await recallDrive();

        // Chrome keeps the permission across visits when it was allowed on
        // every visit; then nothing needs asking and it simply goes on.
        if (this.drive && (await this.drive.queryPermission(permission)) === 'granted') {
            this.run();
        }
    },

    /** The words for the time left, from the layout, which has __(). */
    configure(words) {
        this.words = words;
    },

    get visible() {
        return this.status !== 'idle';
    },

    get waiting() {
        return Math.max(0, this.jobs.length - (this.status === 'running' ? 1 : 0));
    },

    get percent() {
        return this.total > 0 ? Math.round((this.written / this.total) * 100) : 0;
    },

    size,

    /** Add a transfer; it starts at once when nothing else is being written. */
    enqueue(job, drive) {
        this.drive = drive;
        this.jobs.push(job);
        saveQueue(this.jobs);

        if (this.status !== 'running') {
            this.run();
        }
    },

    /** After a reload that did not keep the permission: the click is what asks for it. */
    async resume() {
        this.drive ??= await recallDrive();

        if (!this.drive || (await this.drive.requestPermission(permission)) !== 'granted') {
            return;
        }

        this.run();
    },

    stop() {
        this.jobs = [];
        saveQueue(this.jobs);
        this.abort?.abort();
        this.status = this.status === 'running' ? 'stopped' : 'idle';
    },

    dismiss() {
        if (this.status !== 'running') {
            this.status = 'idle';
        }
    },

    /** Every job in the queue, one after another, until it is empty or one fails. */
    async run() {
        this.status = 'running';

        while (this.jobs.length > 0 && this.status === 'running') {
            const job = this.jobs[0];

            try {
                await this.runOne(job);
            } catch (error) {
                if (this.status !== 'running') {
                    return; // stopped
                }

                this.jobs = [];
                saveQueue(this.jobs);
                this.status = 'error';
                this.message = error?.message ?? String(error);
                return;
            }

            this.jobs.shift();
            saveQueue(this.jobs);
        }

        if (this.status === 'running') {
            this.current = '';
            this.status = 'done';
        }
    },

    async runOne(job) {
        const root = (await rootOf(this.drive, job.root)) ?? (job.confirmed ? this.drive : null);

        if (!root) {
            throw new Error(job.confirm || 'The drive is not the one this was started on.');
        }

        this.label = job.label;
        this.message = '';
        this.written = 0;
        this.total = 0;
        this.skipped = 0;
        this.copied = 0;
        this.samples = sample([], 0);
        this.left = '';
        this.leftAt = 0;
        this.abort = new AbortController();
        this.ticker = setInterval(() => this.tick(), TICK_MS);

        try {
            const plan = await (await fetchOk(job.plan, this.abort.signal)).json();
            this.total = plan.bytes;

            const tree = folders(root);

            await this.copyAll(tree, plan.files);
            await this.writeExtras(tree, plan.extras ?? []);

            // A system that keeps no list, such as OPL: the files were the send.
            if (plan.gamelist !== null) {
                await this.writeGamelist(root, plan.gamelist, job.gamelist);
            }
        } finally {
            clearInterval(this.ticker);
            this.ticker = null;
            this.left = '';
        }
    },

    /**
     * The time left, in words. Changed at most every few seconds, and only
     * when the rounded figure moves, so it counts down rather than flickers.
     */
    tick() {
        this.samples = sample(this.samples, this.copied);
        const seconds = estimate(this.samples, this.total - this.written);
        const now = Date.now();

        if (seconds === null) {
            this.left = '';
            return;
        }

        if (this.left !== '' && now - this.leftAt < ETA_EVERY_MS) {
            return;
        }

        const minutes = roundedMinutes(seconds);
        const words =
            minutes === 0
                ? this.words.underMinute
                : minutes < 60
                  ? this.words.minutes.replace(':n', minutes)
                  : minutes % 60 === 0
                    ? this.words.wholeHours.replace(':h', minutes / 60)
                    : this.words.hours.replace(':h', Math.floor(minutes / 60)).replace(':m', minutes % 60);

        if (words !== this.left) {
            this.left = words;
            this.leftAt = now;
        }
    },

    /**
     * Every file, CONCURRENCY at a time, from one shared queue. The first
     * failure stops the rest from starting; the ones already under way finish,
     * and then it is thrown, so the tray says what went wrong.
     */
    async copyAll(tree, files) {
        const queue = [...files];
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

        const response = await fetchOk(file.url, this.abort.signal);
        const partName = `.${name}.part`;
        const handle = await dir.getFileHandle(partName, { create: true });
        const writable = await handle.createWritable();

        const counted = new TransformStream({
            transform: (chunk, controller) => {
                this.written += chunk.byteLength;
                this.copied += chunk.byteLength;
                controller.enqueue(chunk);
            },
        });

        // Stopped halfway: the part file stays under its temporary name, and
        // is written again from the start next time.
        await response.body.pipeThrough(counted).pipeTo(writable, { signal: this.abort.signal });

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

    /**
     * The target's own files, once the game's are on the drive — OPL's config
     * and art — each fetched as the server makes it and written, unless the
     * drive has one already: somebody may have tuned it there.
     */
    async writeExtras(tree, extras) {
        for (const extra of extras) {
            const parts = extra.destination.split('/');
            const name = parts.pop();
            const folder = parts.join('/');
            this.current = extra.destination;

            if ((await tree.names(folder)).has(name)) {
                this.skipped++;
                continue;
            }

            const response = await fetchOk(extra.url, this.abort.signal);
            const writable = await (await (await tree.handle(folder)).getFileHandle(name, { create: true })).createWritable();
            await writable.write(await response.blob());
            await writable.close();
        }
    },

    async writeGamelist(root, destination, url) {
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

        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/xml', 'X-CSRF-TOKEN': csrf(), Accept: 'application/xml' },
            body: existing,
            signal: this.abort.signal,
        });

        if (!response.ok) {
            throw new Error(await response.text());
        }

        const writable = await (await dir.getFileHandle(name, { create: true })).createWritable();
        await writable.write(await response.text());
        await writable.close();
    },
});
