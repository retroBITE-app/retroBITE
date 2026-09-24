/**
 * Live updates: Reverb over a websocket, instead of every component polling.
 *
 * The server sends signals ("the queues moved", "game 123 was identified"),
 * never the new state; a component that hears one re-reads its own figures
 * exactly as its poll used to. See docs/adr/0002-live-updates-over-reverb.md.
 *
 * Two things here are deliberate:
 *
 * - The connection goes to the page's own host and port, at /app, where nginx
 *   hands it to Reverb inside the container. Nothing is baked into this bundle
 *   at build time: the key comes from a <meta> tag the server writes, and ws
 *   or wss follows the page's own protocol. The project has no certificate, so
 *   plain http is the ordinary case, and a proxy with one in front still works.
 * - window.live wraps every listener so a burst of signals re-renders at most
 *   once a second, always including the last one, and so every listener runs
 *   once more when the socket comes back after a drop — a signal sent while a
 *   container was restarting is otherwise simply gone.
 */
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const THROTTLE_MS = 1000;

const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.getAttribute('content') || null;

const key = meta('reverb-key');

/** Leading and trailing: the first call runs at once, a burst runs once more at its end. */
function throttle(fn) {
    let timer = null;
    let pending = null;

    const run = (arg) => {
        fn(arg);
        timer = setTimeout(() => {
            timer = null;

            if (pending !== null) {
                const next = pending;
                pending = null;
                run(next);
            }
        }, THROTTLE_MS);
    };

    return (arg) => {
        if (timer === null) {
            run(arg);
        } else {
            pending = arg;
        }
    };
}

/** Listeners to call once more when the socket reconnects. */
const listeners = new Set();

function subscribe(channelName, eventName, accept, callback) {
    if (!window.Echo) {
        return () => {};
    }

    const throttled = throttle(callback);
    const handler = (event) => {
        if (accept(event)) {
            throttled(event?.what ?? null);
        }
    };
    const onReconnect = () => throttled('reconnect');

    const channel = window.Echo.private(channelName);
    channel.listen(eventName, handler);
    listeners.add(onReconnect);

    return () => {
        channel.stopListening(eventName, handler);
        listeners.delete(onReconnect);
    };
}

if (key) {
    window.Pusher = Pusher;

    const secure = window.location.protocol === 'https:';
    const port = Number(window.location.port) || (secure ? 443 : 80);

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: window.location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS: secure,
        enabledTransports: ['ws', 'wss'],
        csrfToken: meta('csrf-token'),
    });

    let connectedBefore = false;

    window.Echo.connector.pusher.connection.bind('connected', () => {
        if (connectedBefore) {
            listeners.forEach((run) => run());
        }

        connectedBefore = true;
    });
}

window.live = {
    /**
     * Run callback when the sidebar's `what` (activity, quota, storage) changes.
     *
     * @returns {() => void} stop listening
     */
    system(what, callback) {
        return subscribe('system', 'SystemUpdated', (event) => event?.what === what, callback);
    },

    /**
     * Run callback(what) when work on one game ends: hashed, identified,
     * artwork, rating, failed — or 'reconnect' after a dropped socket.
     *
     * @returns {() => void} stop listening
     */
    game(gameId, callback) {
        return subscribe(`games.${gameId}`, 'GameUpdated', () => true, callback);
    },
};
