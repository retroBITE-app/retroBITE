import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { defineConfig, lazyPlugins } from 'vite-plus';

// The address the browser reaches the dev server at. HOST_IP is the machine's
// LAN address (.env, shared with the FTP passive mode), so a page opened from
// another device on the network fetches its assets from here rather than from
// its own localhost, where nothing is listening. Unset, it stays localhost.
const devHost = process.env.HOST_IP || 'localhost';

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ]),
    server: {
        // Bind every interface: the dev server runs inside the web container
        // and would otherwise answer only to localhost within it, leaving the
        // published port dead.
        host: '0.0.0.0',
        // What the browser is told to fetch from. Without it laravel-vite-plugin
        // writes the address the server bound to into public/hot — 0.0.0.0,
        // which a browser cannot route to.
        origin: `http://${devHost}:1337`,
        // And where the hot-reload socket connects, for the same reason.
        hmr: { host: devHost, port: 1337 },
        // Fixed port so the dev container's CSP and any bookmarked URL stay
        // valid. strictPort makes a clash fail loudly instead of silently
        // moving to 1338 and leaving public/hot pointing at a dead port.
        port: 1337,
        strictPort: true,
        cors: true,
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/storage/app/**',
                '**/storage/framework/views/**',
                '**/vendor/**',
            ],
        },
    },
});
