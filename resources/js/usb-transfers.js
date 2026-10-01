/**
 * Copying onto a USB drive, for the whole tab rather than one page: an Alpine
 * store (`$store.usb`) that holds a queue of transfers and runs them one at a
 * time, whatever page is open; one that fails does not stop the next. The Send
 * to modal only adds to it; the tray in the layout (components/transfer-tray)
 * shows how each of them is going.
 *
 * Moving between pages is wire:navigate, which swaps the page and keeps this
 * running. A reload or a closed tab cannot keep it, so what is left of the
 * queue is kept in localStorage and taken up again on the next page: the plan
 * is asked for afresh and every file already on the drive is skipped, so it
 * goes on in effect where it stopped. The browser is asked to warn before a
 * reload while something is being written.
 *
 * Whether a file on the drive is whole is not asked of the drive: a memory
 * card answers a size slowly, one file at a time, and comes in every
 * formatting there is. A journal in this browser (IndexedDB) holds every file
 * from the moment its writing starts until it is closed, as a .part name
 * would; a name the drive's listing has is skipped unless the journal still
 * holds it, or the listing has the browser's .crswap beside it — what a crash
 * leaves, journal or not. Each folder is listed once, and nothing else is read.
 *
 * Nothing on the drive is overwritten or removed, except the game's own entry
 * in the game list and the game's other versions: a file already there and
 * finished is skipped, a target's own file (OPL's config and art) already
 * there is left as it is, and every file is written straight to its own name.
 * Another version of the game, sent before — the European copy where the
 * American one is now chosen — is removed with its artwork and its entry, but
 * only once this one is on the drive (the plan's `replaces`). The browser already
 * writes through a swap file (.crswap) and puts the bytes under the name only
 * on close, so a half-written ROM never sits there; a renamed temporary file
 * would add nothing, and Chromium refuses the rename once the click that
 * started it is a few seconds old. See
 * docs/adr/0003-transfers-from-the-browser.md.
 */

import { estimate, roundedMinutes, sample } from './transfer-eta';

const DB = 'retrobite-transfer';
const STORE = 'drives';
const JOURNAL = 'unfinished'; // files being written, by drive, root and path
const DRIVE_KEY = 'drive';
const QUEUE_KEY = 'retrobite-usb-transfers';

// Files copied at once. A console is thousands of small files, and each spends
// most of its time waiting — on the server's answer, on the drive's directory —
// rather than moving bytes, so a few in flight go several times as fast.
const CONCURRENCY = 4;

// How often the time left is worked out, and how often what it says may change.
const TICK_MS = 1_000;
const ETA_EVERY_MS = 5_000;

/** A tiny IndexedDB wrapper: Chrome can keep a directory handle there, and the journal. */
function idb(mode, run, name = STORE) {
    return new Promise((resolve, reject) => {
        const open = indexedDB.open(DB, 2);
        open.onupgradeneeded = () => {
            for (const store of [STORE, JOURNAL]) {
                if (!open.result.objectStoreNames.contains(store)) {
                    open.result.createObjectStore(store);
                }
            }
        };
        open.onerror = () => reject(open.error);
        open.onsuccess = () => {
            const store = open.result.transaction(name, mode).objectStore(name);
            const request = run(store);
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        };
    });
}

export const rememberDrive = (handle) => idb('readwrite', (store) => store.put(handle, DRIVE_KEY));
export const recallDrive = () => idb('readonly', (store) => store.get(DRIVE_KEY)).catch(() => null);

/**
 * The files of one root on one drive that were being written and never
 * closed. Entered before the first byte and left after the close, so a reload,
 * a crash or a failure in between keeps the file here and the next send
 * writes it again. Storage can be blocked: then it remembers nothing, and the
 * .crswap in the listing is what is left to go on.
 */
function journal(prefix) {
    const key = (path) => `${prefix}${path}`;

    return {
        async held() {
            try {
                const keys = await idb('readonly', (store) => store.getAllKeys(), JOURNAL);
                return new Set(keys.filter((k) => k.startsWith(prefix)).map((k) => k.slice(prefix.length)));
            } catch {
                return new Set();
            }
        },
        start: (path) => idb('readwrite', (store) => store.put(Date.now(), key(path)), JOURNAL).catch(() => null),
        finish: (path) => idb('readwrite', (store) => store.delete(key(path)), JOURNAL).catch(() => null),
    };
}

// Chromium's swap file beside a file being written: `name.crswap`, or
// `name.1.crswap` when that is taken.
const SWAP = /(\.\d+)?\.crswap$/;

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

/** The directory for a relative path, or null where any of it is missing: nothing is made. */
async function existingDirectory(root, parts) {
    let dir = root;
    for (const part of parts) {
        try {
            dir = await dir.getDirectoryHandle(part);
        } catch {
            return null;
        }
    }
    return dir;
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

    // One listing, read for both: the names there, and the names a swap
    // file beside them says were left half-written.
    const listing = (path) => {
        if (!listings.has(path)) {
            listings.set(path, (async () => {
                const names = new Set();
                const swapped = new Set();
                for await (const name of (await handle(path)).keys()) {
                    names.add(name);
                    if (SWAP.test(name)) {
                        swapped.add(name.replace(SWAP, ''));
                    }
                }
                return { names, swapped };
            })());
        }
        return listings.get(path);
    };

    return {
        handle,
        names: async (path) => (await listing(path)).names,
        swapped: async (path) => (await listing(path)).swapped,
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

/**
 * Runs `run` over every item, CONCURRENCY at a time, from one shared queue.
 * The first failure stops the rest from starting; the ones already under way
 * finish, and then it is thrown, so the tray says what went wrong.
 */
async function inParallel(items, run) {
    const queue = [...items];
    let failure = null;

    const worker = async () => {
        while (failure === null && queue.length > 0) {
            const item = queue.shift();
            try {
                await run(item);
            } catch (error) {
                failure ??= error;
            }
        }
    };

    await Promise.all(Array.from({ length: Math.min(CONCURRENCY, items.length) }, worker));

    if (failure !== null) {
        throw failure;
    }
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

/** What a job is, without how it is going: what the queue keeps across a reload. */
const definition = ({ label, plan, gamelist, root, confirm, confirmed }) => ({ label, plan, gamelist, root, confirm, confirmed });

/** A job as it starts: waiting, with nothing written yet. */
const fresh = (job, id) => ({
    ...definition(job),
    id,
    state: 'waiting', // waiting | running | done | error | stopped
    message: '',
    written: 0,
    total: 0,
    copied: 0, // bytes actually copied, which the time left is worked out from
    skipped: 0,
    removed: 0, // files of the game's other versions taken off the drive
    phase: '', // checking | copying | gamelist, while it runs
    checking: 0, // files to look for on the drive
    checked: 0,
    current: '',
    left: '',
    samples: [],
    leftAt: 0,
});

/**
 * The store. A job is { label, plan, gamelist, root, confirm, confirmed }:
 * what to call it, the URLs of its plan and its merged game list, the folders
 * that mark its target's root and what to say when the drive has none, and
 * whether the person agreed to write it to a drive holding none of them. Each
 * keeps its own progress, and one that fails is marked so and the next one
 * goes on: a console that cannot be written says why without holding back the
 * rest of the queue.
 */
export default () => ({
    jobs: [], // every one of this tab's, finished, running and waiting, in order
    status: 'idle', // idle | running | interrupted | done | stopped
    words: { underMinute: '', minutes: '', hours: '', wholeHours: '' },
    drive: null,
    ticker: null,
    abort: null,
    turn: 0, // which run() is the live one, so a stopped one cannot carry on beside a new one
    nextId: 1,

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

        this.jobs = jobs.map((job) => fresh(job, this.nextId++));
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

    /** The one being written now, if any. */
    get active() {
        return this.jobs.find((job) => job.state === 'running') ?? null;
    },

    get waiting() {
        return this.jobs.filter((job) => job.state === 'waiting').length;
    },

    get failures() {
        return this.jobs.filter((job) => job.state === 'error');
    },

    get finished() {
        return this.jobs.filter((job) => ['done', 'error', 'stopped'].includes(job.state)).length;
    },

    get skipped() {
        return this.jobs.reduce((sum, job) => sum + (job.state === 'done' ? job.skipped : 0), 0);
    },

    get removed() {
        return this.jobs.reduce((sum, job) => sum + (job.state === 'done' ? job.removed : 0), 0);
    },

    /** How far it is: through the checks while they run, then through the bytes left to copy. */
    percent(job) {
        if (job.phase === 'checking') {
            return job.checking > 0 ? Math.round((job.checked / job.checking) * 100) : 0;
        }

        return job.total > 0 ? Math.round((job.written / job.total) * 100) : 100;
    },

    size,

    /** What is still to do, kept for a reload: the running one and those waiting. */
    save() {
        saveQueue(this.jobs.filter((job) => ['waiting', 'running'].includes(job.state)).map(definition));
    },

    /** Add a transfer; it starts at once when nothing else is being written. */
    enqueue(job, drive) {
        this.drive = drive;
        this.jobs.push(fresh(job, this.nextId++));
        this.save();

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

    /** Everything: the one being written stops where it is, and those waiting are dropped. */
    stop() {
        const active = this.active;
        this.jobs = this.jobs.filter((job) => job.state !== 'waiting');
        this.save();

        if (active) {
            active.state = 'stopped';
            this.abort?.abort();
        }

        this.turn++;
        this.status = this.status === 'running' ? 'stopped' : this.jobs.length > 0 ? 'done' : 'idle';
    },

    /** One of them: dropped if it is waiting, stopped if it is being written, and the queue goes on. */
    cancel(job) {
        if (job.state === 'running') {
            job.state = 'stopped';
            this.abort?.abort();
        } else if (job.state === 'waiting') {
            this.jobs = this.jobs.filter((other) => other.id !== job.id);
        }

        this.save();

        if (this.status === 'interrupted' && this.waiting === 0) {
            this.status = this.jobs.length > 0 ? 'done' : 'idle';
        }
    },

    /** Clears the finished ones from the list; the tray goes once nothing is left. */
    dismiss() {
        this.jobs = this.jobs.filter((job) => ['waiting', 'running'].includes(job.state));

        if (this.status !== 'running' && this.status !== 'interrupted') {
            this.status = this.jobs.length > 0 ? this.status : 'idle';
        }
    },

    /**
     * Every waiting job, one after another, until none is left. One that fails
     * is marked with what went wrong, and the next one starts.
     */
    async run() {
        const turn = ++this.turn;
        this.status = 'running';

        let job;
        while (turn === this.turn && (job = this.jobs.find((waiting) => waiting.state === 'waiting'))) {
            job.state = 'running';

            try {
                await this.runOne(job);
                job.state = job.state === 'running' ? 'done' : job.state;
            } catch (error) {
                if (job.state === 'running') {
                    job.state = 'error';
                    job.message = error?.message ?? String(error);
                }
            }

            job.current = '';
            job.phase = '';
            this.save();
        }

        if (turn === this.turn) {
            this.status = 'done';
        }
    },

    async runOne(job) {
        const root = (await rootOf(this.drive, job.root)) ?? (job.confirmed ? this.drive : null);

        if (!root) {
            throw new Error(job.confirm || 'The drive is not the one this was started on.');
        }

        job.samples = sample([], 0);
        this.abort = new AbortController();
        this.ticker = setInterval(() => this.tick(job), TICK_MS);

        try {
            const plan = await (await fetchOk(job.plan, this.abort.signal)).json();
            const tree = folders(root);
            const log = journal(`${this.drive.name}|${root.name}|`);

            // What the drive already has is found before anything is
            // written, so the copying is only of what is missing and the
            // bar and the time left count only that.
            job.phase = 'checking';
            job.checking = plan.files.length;
            const missing = await this.missing(job, tree, plan.files, await log.held());
            job.total = missing.reduce((sum, file) => sum + file.size, 0);

            job.phase = 'copying';
            await this.copyAll(job, tree, missing, log);
            await this.writeExtras(job, tree, plan.extras ?? []);
            await this.removeReplaced(job, root, plan.replaces ?? []);

            // A system that keeps no list, such as OPL: the files were the send.
            if (plan.gamelist !== null) {
                job.phase = 'gamelist';
                await this.writeGamelist(job, root, plan.gamelist, job.gamelist);
            }
        } finally {
            clearInterval(this.ticker);
            this.ticker = null;
            job.left = '';
        }
    },

    /**
     * The time left, in words. Changed at most every few seconds, and only
     * when the rounded figure moves, so it counts down rather than flickers.
     */
    tick(job) {
        job.samples = sample(job.samples, job.copied);
        const seconds = estimate(job.samples, job.total - job.written);
        const now = Date.now();

        if (seconds === null) {
            job.left = '';
            return;
        }

        if (job.left !== '' && now - job.leftAt < ETA_EVERY_MS) {
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

        if (words !== job.left) {
            job.left = words;
            job.leftAt = now;
        }
    },

    /**
     * The files the drive does not have whole, every one of the plan's looked
     * at before the first is written, from the folders' listings alone: a
     * name there is whole unless the journal (`unfinished`) still holds it or
     * a .crswap sits beside it. Nothing is asked of a file itself. Those
     * already there are counted as skipped.
     */
    async missing(job, tree, files, unfinished) {
        const missing = [];

        for (const file of files) {
            const parts = file.destination.split('/');
            const name = parts.pop();
            const folder = parts.join('/');

            const whole =
                (await tree.names(folder)).has(name) &&
                !unfinished.has(file.destination) &&
                !(await tree.swapped(folder)).has(name);

            if (whole) {
                job.skipped++;
            } else {
                missing.push(file);
            }

            job.checked++;
        }

        return missing;
    },

    /** Every file the drive is missing, CONCURRENCY at a time, each in the journal while it is written. */
    async copyAll(job, tree, files, log) {
        await inParallel(files, async (file) => {
            await log.start(file.destination);
            await this.copy(job, tree, file);
            await log.finish(file.destination);
        });
    },

    async copy(job, tree, file) {
        const parts = file.destination.split('/');
        const name = parts.pop();
        const folder = parts.join('/');
        const dir = await tree.handle(folder);
        job.current = file.destination;

        const response = await fetchOk(file.url, this.abort.signal);
        const writable = await (await dir.getFileHandle(name, { create: true })).createWritable();

        const counted = new TransformStream({
            transform: (chunk, controller) => {
                job.written += chunk.byteLength;
                job.copied += chunk.byteLength;
                controller.enqueue(chunk);
            },
        });

        // Stopped halfway: the bytes are in the browser's .crswap beside it,
        // and the file under the real name, made empty by getFileHandle(), is
        // left at 0 bytes. Still in the journal, it is written again from the
        // start next time.
        await response.body.pipeThrough(counted).pipeTo(writable, { signal: this.abort.signal });
    },

    /**
     * The target's own files, once the game's are on the drive — OPL's config
     * and art — each fetched as the server makes it and written, unless the
     * drive has one already: somebody may have tuned it there.
     */
    async writeExtras(job, tree, extras) {
        for (const extra of extras) {
            const parts = extra.destination.split('/');
            const name = parts.pop();
            const folder = parts.join('/');
            job.current = extra.destination;

            if ((await tree.names(folder)).has(name)) {
                job.skipped++;
                continue;
            }

            const response = await fetchOk(extra.url, this.abort.signal);
            const writable = await (await (await tree.handle(folder)).getFileHandle(name, { create: true })).createWritable();
            await writable.write(await response.blob());
            await writable.close();
        }
    },

    /**
     * The game's other versions, where they are on the drive: removed once
     * this one has arrived, so the front-end lists the game once. A path not
     * there is nothing to do, and no folder is made looking for it.
     */
    async removeReplaced(job, root, paths) {
        for (const path of paths) {
            const parts = path.split('/');
            const name = parts.pop();
            const dir = await existingDirectory(root, parts);

            if (dir === null) {
                continue;
            }

            job.current = path;

            try {
                await dir.removeEntry(name);
                job.removed++;
            } catch (error) {
                if (error?.name !== 'NotFoundError') {
                    throw error;
                }
            }
        }
    },

    async writeGamelist(job, root, destination, url) {
        const parts = destination.split('/');
        const name = parts.pop();
        const dir = await directoryFor(root, parts);
        job.current = destination;

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
