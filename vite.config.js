import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { defineConfig, lazyPlugins } from 'vite-plus';

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
