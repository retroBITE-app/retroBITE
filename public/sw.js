// The service worker that makes retroBITE installable as an app.
//
// It caches nothing the app renders. Every page is a Livewire page that
// carries a CSRF token and changes with the library, so a stored copy would be
// a page that cannot be used. The one thing it stores is offline.html, which a
// navigation gets when the server cannot be reached: the installed window then
// says the server is down instead of showing the browser's error page.
//
// Bump VERSION when offline.html or its icon changes; the old cache is dropped
// on activate.
const VERSION = 'v1';
const CACHE = `retrobite-offline-${VERSION}`;
const OFFLINE = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE)
            .then((cache) => cache.addAll([OFFLINE, '/icons/icon-192.png']))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys.filter((key) => key.startsWith('retrobite-') && key !== CACHE)
                    .map((key) => caches.delete(key)),
            ))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    // The offline page's own icon, which the page asks for while offline.
    if (request.method === 'GET' && new URL(request.url).pathname === '/icons/icon-192.png') {
        event.respondWith(fetch(request).catch(() => caches.match(request)));
        return;
    }

    // Anything but a page load goes to the network untouched: Livewire's
    // requests, uploads, artwork, ROM downloads and the Reverb socket.
    if (request.mode !== 'navigate') {
        return;
    }

    event.respondWith(fetch(request).catch(() => caches.match(OFFLINE)));
});
