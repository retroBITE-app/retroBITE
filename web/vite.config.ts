import { defineConfig, loadEnv } from 'vite'
import { fileURLToPath, URL } from 'node:url'
import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'

const projectRoot = fileURLToPath(new URL('..', import.meta.url))

export default defineConfig(({ mode }) => {
  // The project root .env is the single source of truth for ports — it is also
  // what docker-compose and the PHP Vite helper read, so all three agree.
  const env = loadEnv(mode, projectRoot, '')

  // strictPort: never silently drift to 5173+1 when another project owns 5173 —
  // the PHP helper emits a fixed URL and would end up loading that project's app.
  const port = Number(env.VITE_PORT || 5173)

  return {
    plugins: [tailwindcss(), vue()],
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
      },
    },
    build: {
      outDir: 'public/build',
      manifest: true,
      rollupOptions: {
        input: 'resources/js/app.ts',
      },
    },
    server: {
      host: true,
      port,
      strictPort: true,
      // No `https` key: Vite 6 types it as options, not a boolean, and plain HTTP
      // is what the LAN dev flow uses anyway.
      cors: true,
      hmr: {
        host: 'localhost',
        clientPort: port,
        protocol: 'ws',
      },
    },
  }
})
