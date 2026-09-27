/**
 * The browser half of a ROM upload: check, slice, send, resume.
 *
 * The server decides everything that matters — begin() refuses a file before
 * a byte is sent, and each chunk is checked against what is already staged —
 * so the checks here only spare somebody a wait for an answer they could have
 * had at once. The chunk size comes back from begin() rather than living here,
 * so the two halves cannot disagree about it.
 */

/** Attempts per chunk before the file is given up on. */
const RETRIES = 3;

/** A chunk answered with the offset it was sent at this many times has stalled. */
const STALLS = 3;

const UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];

/**
 * @param {{
 *     extensions: string[],
 *     excluded: string[],
 *     destination: string,
 *     perGameFolders: boolean,
 *     csrf: string,
 *     messages: { wrongType: string, excluded: string, failed: string, cancelled: string, expired: string },
 * }} config
 */
export default function romUpload(config) {
    return {
        destination: config.destination,
        files: [],
        rejected: [],
        running: false,
        stopping: false,
        controller: null,
        sequence: 0,

        init() {
            this.guard = (event) => {
                if (! this.running) return;

                event.preventDefault();
                event.returnValue = '';
            };

            window.addEventListener('beforeunload', this.guard);
        },

        destroy() {
            window.removeEventListener('beforeunload', this.guard);
        },

        get queued() {
            return this.files.filter((entry) => entry.status === 'queued').length;
        },

        get settled() {
            return this.files.some((entry) => ['done', 'failed', 'cancelled'].includes(entry.status));
        },

        /**
         * The files as the list draws them: one group per game folder where
         * the layout files a game per folder, one unnamed group otherwise.
         */
        get groups() {
            if (! config.perGameFolders) return [{ folder: '', entries: this.files }];

            const groups = [];

            for (const entry of this.files) {
                let group = groups.find((candidate) => candidate.folder === entry.folder);

                if (group === undefined) {
                    group = { folder: entry.folder, entries: [] };
                    groups.push(group);
                }

                group.entries.push(entry);
            }

            return groups;
        },

        /**
         * The folder a file's game goes in, guessed from its name: the
         * extension and any "(Disc 2)" or "(Track 1)" taken off, so a cue and
         * its bins, or every disc of a set, land in one folder. Only a guess —
         * the list lets it be changed before anything is sent.
         */
        folderFor(name) {
            const guessed = name
                .replace(/\.[^.]+$/, '')
                .replace(/\s*[([]\s*(disc|disk|cd|track)\s*\d+[^)\]]*[)\]]/gi, '')
                .replace(/[\s._-]+$/, '')
                .trim();

            return guessed !== '' ? guessed : name.replace(/\.[^.]+$/, '');
        },

        /** Move every file still waiting in one folder to another name. */
        renameGroup(from, to) {
            const folder = to.trim();

            for (const entry of this.files) {
                if (entry.folder === from && entry.status === 'queued') entry.folder = folder;
            }
        },

        pick(event) {
            this.add(Array.from(event.target.files ?? []));

            // Cleared so picking the same file again after a failure still
            // fires a change.
            event.target.value = '';
        },

        drop(event) {
            if (this.running) return;

            this.add(Array.from(event.dataTransfer?.files ?? []));
        },

        add(list) {
            this.rejected = [];

            for (const file of list) {
                const reason = this.refusal(file.name);

                if (reason !== null) {
                    this.rejected.push({ name: file.name, message: reason });

                    continue;
                }

                const folder = config.perGameFolders ? this.folderFor(file.name) : '';

                if (this.files.some((entry) => entry.name === file.name && entry.folder === folder && entry.status === 'queued')) continue;

                this.files.push({
                    key: ++this.sequence,
                    file,
                    name: file.name,
                    folder,
                    size: file.size,
                    sent: 0,
                    status: 'queued',
                    message: '',
                });
            }
        },

        /** The scanner's two tests, as begin() applies them: the last extension, then the ignore list. */
        refusal(name) {
            const lower = name.toLowerCase();
            const dot = lower.lastIndexOf('.');
            const extension = dot === -1 ? '' : lower.slice(dot + 1);

            if (! config.extensions.includes(extension)) return config.messages.wrongType;
            if (config.excluded.includes(lower)) return config.messages.excluded;

            return null;
        },

        remove(entry) {
            this.files = this.files.filter((candidate) => candidate.key !== entry.key);
        },

        clearSettled() {
            this.files = this.files.filter((entry) => ! ['done', 'failed', 'cancelled'].includes(entry.status));
            this.rejected = [];
        },

        async start() {
            if (this.running) return;

            this.running = true;
            this.stopping = false;

            let placed = 0;

            for (const entry of this.files) {
                if (this.stopping) break;
                if (entry.status !== 'queued') continue;

                if (await this.send(entry)) placed++;
            }

            this.running = false;
            this.controller = null;

            // Once for the batch, not per file.
            if (placed > 0) await this.$wire.uploaded(placed);
        },

        stop() {
            this.stopping = true;
            this.controller?.abort();
        },

        async send(entry) {
            entry.status = 'uploading';
            entry.message = '';
            entry.sent = 0;

            const begun = await this.$wire.begin(entry.name, entry.size, this.destination, entry.folder);

            if (! begun.ok) return this.fail(entry, begun.message);

            // Stopped while begin() was out: nothing is sent, the staged file goes.
            if (this.stopping) {
                await this.$wire.cancel(begun.id);
                entry.status = 'cancelled';
                entry.message = config.messages.cancelled;

                return false;
            }

            this.controller = new AbortController();

            let offset = 0;
            let stalls = 0;

            while (offset < entry.size) {
                const result = await this.chunk(begun.url, entry.file, offset, begun.chunk);

                if (result.aborted) {
                    await this.$wire.cancel(begun.id);
                    entry.status = 'cancelled';
                    entry.message = config.messages.cancelled;

                    return false;
                }

                if (result.error !== undefined) {
                    await this.$wire.cancel(begun.id);

                    return this.fail(entry, result.error);
                }

                stalls = result.received > offset ? 0 : stalls + 1;

                if (stalls >= STALLS) {
                    await this.$wire.cancel(begun.id);

                    return this.fail(entry, config.messages.failed);
                }

                offset = result.received;
                entry.sent = offset;
            }

            const finished = await this.$wire.finish(begun.id);

            if (! finished.ok) return this.fail(entry, finished.message);

            entry.status = 'done';

            return true;
        },

        /** One chunk, retried on a dropped connection or a server error. A 409 says where to carry on. */
        async chunk(url, file, offset, size) {
            for (let attempt = 0; attempt < RETRIES; attempt++) {
                let response;

                try {
                    response = await fetch(url, {
                        method: 'POST',
                        credentials: 'same-origin',
                        signal: this.controller.signal,
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/octet-stream',
                            'X-CSRF-TOKEN': config.csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-Upload-Offset': String(offset),
                        },
                        body: file.slice(offset, offset + size),
                    });
                } catch (error) {
                    if (error.name === 'AbortError') return { aborted: true };

                    await pause(attempt);

                    continue;
                }

                if (response.ok || response.status === 409) {
                    const body = await response.json();

                    return { received: Number(body.received) };
                }

                // A lapsed session or CSRF token will not come back by trying again.
                if (response.status === 401 || response.status === 419) return { error: config.messages.expired };

                if (response.status < 500) {
                    const body = await response.json().catch(() => ({}));

                    return { error: body.message ?? config.messages.failed };
                }

                await pause(attempt);
            }

            return { error: config.messages.failed };
        },

        fail(entry, message) {
            entry.status = 'failed';
            entry.message = message ?? config.messages.failed;

            return false;
        },

        percent(entry) {
            return entry.size === 0 ? 0 : Math.floor((entry.sent / entry.size) * 100);
        },

        bytes(count) {
            let value = count;
            let unit = 0;

            while (value >= 1024 && unit < UNITS.length - 1) {
                value /= 1024;
                unit++;
            }

            return `${value.toFixed(unit === 0 ? 0 : 1)} ${UNITS[unit]}`;
        },
    };
}

/** Back off a little longer after each failed attempt. */
function pause(attempt) {
    return new Promise((resolve) => setTimeout(resolve, 1000 * (attempt + 1)));
}
