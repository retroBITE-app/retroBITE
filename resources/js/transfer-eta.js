/**
 * How long a USB transfer has left, roughly: the bytes still to go over the
 * speed of the last half minute.
 *
 * The speed is measured on bytes actually copied, not on progress: a file
 * already on the drive is skipped and counted as written at once, and a
 * console sent a second time would otherwise look finished in seconds. And it
 * is measured against the clock, over a window, so what bytes do not show —
 * Chrome finishing a file on close, a drive flushing its cache, the wait on
 * each small artwork file — is in it too.
 */

const WINDOW_MS = 30_000;

// Before this, the speed is the first file's, not the drive's.
const MIN_MS = 5_000;
const MIN_BYTES = 1024 * 1024;

/** A sample of the bytes copied so far, with the samples older than the window let go. */
export function sample(samples, copied, now = Date.now()) {
    const kept = samples.filter((s) => now - s.t <= WINDOW_MS);
    kept.push({ t: now, copied });
    return kept;
}

/** Seconds left, or null while there is not enough to go on. */
export function estimate(samples, remaining) {
    if (samples.length < 2) {
        return null;
    }

    const first = samples[0];
    const last = samples[samples.length - 1];
    const ms = last.t - first.t;
    const bytes = last.copied - first.copied;

    if (ms < MIN_MS || bytes < MIN_BYTES) {
        return null;
    }

    return Math.max(0, remaining) / (bytes / ms) / 1000;
}

/**
 * Minutes, rounded as coarsely as the estimate deserves: to the minute under
 * ten, to five under an hour, to ten after. 0 is under a minute.
 */
export function roundedMinutes(seconds) {
    const minutes = seconds / 60;

    if (minutes < 1) {
        return 0;
    }

    if (minutes < 10) {
        return Math.round(minutes);
    }

    const step = minutes < 60 ? 5 : 10;

    return Math.round(minutes / step) * step;
}
