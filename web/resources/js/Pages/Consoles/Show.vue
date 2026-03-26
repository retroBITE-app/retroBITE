<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import PageHeader from '../../Components/PageHeader.vue'

const props = defineProps<{
  console: string
  meta: { name: string; icon: string; file_icon: string }
  games: Array<{ file_name: string; title?: string; file_size?: number; region?: string }>
  extensions: { files: string[]; bios: string[] }
  type: string | null
}>()

const scanning = ref(false)

const filters = [
  { key: null,    label: 'All'   },
  { key: 'files', label: 'Games' },
  { key: 'bios',  label: 'BIOS'  },
] as const

function setFilter(type: string | null) {
  router.get(`/consoles/${props.console}`, type ? { type } : {}, { preserveScroll: true })
}

function formatSize(bytes?: number): string {
  if (!bytes) return '—'
  const gb = bytes / 1024 / 1024 / 1024
  return gb >= 1 ? `${gb.toFixed(2)} GB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

function scan() {
  scanning.value = true
  router.post(`/consoles/${props.console}/scan`, {}, {
    onFinish: () => { scanning.value = false },
  })
}
</script>

<template>
  <div>

    <!-- Header -->
    <PageHeader>
      <template #title>
        <div class="flex items-center gap-4">
          <Link href="/consoles" class="text-zinc-500 hover:text-zinc-300 transition-colors text-sm">← Back</Link>
          <img :src="meta.icon" :alt="meta.name" class="h-8 w-8 object-contain opacity-80" />
          <h1 class="text-2xl font-semibold text-zinc-100">{{ meta.name }}</h1>
        </div>
      </template>
      <template #actions>
        <button
          @click="scan"
          :disabled="scanning"
          class="px-4 py-2 rounded-md text-sm font-medium bg-emerald-600 hover:bg-emerald-500 text-white transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {{ scanning ? 'Scanning…' : 'Scan directory' }}
        </button>
      </template>
    </PageHeader>

    <!-- Action bar -->
    <div class="flex items-center gap-2 mb-4">
      <button
        v-for="f in filters"
        :key="String(f.key)"
        @click="setFilter(f.key)"
        :class="type === f.key
          ? 'bg-zinc-700 text-zinc-100'
          : 'text-zinc-400 hover:text-zinc-200 hover:bg-zinc-800'"
        class="px-3 py-1 rounded text-xs font-medium transition-colors cursor-pointer"
      >{{ f.label }}</button>
    </div>

    <!-- Empty state -->
    <div v-if="games.length === 0" class="rounded-lg border border-dashed border-zinc-700 p-12 text-center">
      <p class="text-zinc-500 text-sm">No games found. Try scanning the directory.</p>
    </div>

    <!-- Game list -->
    <ul v-else class="space-y-2">
      <li
        v-for="game in games"
        :key="game.file_name"
        class="group flex items-center justify-between rounded-lg bg-zinc-800 border border-zinc-700 px-5 py-3 hover:border-emerald-500/50 hover:bg-zinc-800/80 transition-colors cursor-pointer"
      >
        <span class="text-zinc-200 text-sm truncate">{{ game.title ?? game.file_name }}</span>
        <div class="flex items-center gap-4 shrink-0 ml-4">
          <span v-if="game.region" class="text-xs font-mono text-zinc-500 uppercase">{{ game.region }}</span>
          <span class="text-xs text-zinc-600 group-hover:text-white transition-colors">{{ formatSize(game.file_size) }}</span>
        </div>
      </li>
    </ul>

  </div>
</template>
