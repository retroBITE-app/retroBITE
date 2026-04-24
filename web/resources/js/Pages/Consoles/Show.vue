<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import { formatSize } from '@/Helpers/format'
import PageHeader from '@/Components/PageHeader.vue'
import FileUploader from '@/Components/FileUploader.vue'
import CreateDirectory from '@/Components/CreateDirectory.vue'

const props = defineProps<{
  console: string
  meta: { name: string; icon: string; file_icon: string; folder: string }
  games: Array<{ file_name: string; title?: string; file_size?: number; region?: string, file_md5?: string, logo_url?: string | null, cover_url?: string | null, regionMeta?: { name: string; flag: string; codes: string[]; icon: string } | null, identified_at?: number | null }>
  extensions: { files: string[]; bios: string[] }
  type: string
  uploadDirs: Array<{ value: string; label: string }>
}>()

const scanning = ref(false)

const filters = [
  { key: 'all',   label: 'All'   },
  { key: 'files', label: 'Games' },
  { key: 'bios',  label: 'BIOS'  },
] as const

function setFilter(type: string) {
  router.get(`/consoles/${props.console}`, { type }, { preserveScroll: true })
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
        <div class="flex items-center gap-2">
          <CreateDirectory
            :console="props.console"
            :console-name="meta.name"
            :folder="meta.folder"
            :dirs="uploadDirs"
            @done="router.reload()"
          />
          <FileUploader
            :console="props.console"
            :console-name="meta.name"
            :accepted-extensions="[...new Set([...extensions.files, ...extensions.bios])]"
            :upload-dirs="uploadDirs"
            @done="router.reload()"
          />
          <button
            @click="scan"
            :disabled="scanning"
            class="px-4 py-2 rounded-md text-sm font-medium bg-emerald-600 hover:bg-emerald-500 text-white transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {{ scanning ? 'Scanning…' : 'Scan directory' }}
          </button>
        </div>
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
      <p class="text-zinc-500 text-sm">No files found. Try scanning the directory.</p>
    </div>

    <!-- Game grid -->
    <ul v-else class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-7 gap-4">
      <li
        v-for="game in games"
        :key="game.file_name"
        class="group"
      >
        <Link
          :href="`/consoles/${props.console}/${encodeURIComponent(game.file_name)}`"
          class="flex flex-col gap-2"
        >
          <!-- Cover -->
          <div class="relative w-full aspect-5/7 rounded-lg overflow-hidden bg-zinc-800 border-2 border-zinc-700 group-hover:border-emerald-500/60 transition-colors flex items-center justify-center">
            <img
              v-if="game.cover_url"
              :src="game.cover_url"
              :alt="game.title"
              class="w-full h-full object-cover"
            />
            <div v-else class="flex flex-col items-center gap-2 text-zinc-600">
              <img :src="meta.file_icon" :alt="meta.name" class="w-16 h-16 object-contain opacity-30" />
            </div>
          </div>

          <!-- Title + meta -->
          <div class="px-0.5 min-w-0">
            <p class="text-sm text-zinc-200 truncate group-hover:text-white transition-colors" :title="game.title">{{ game.title }}</p>
            <div class="mt-1 flex items-center gap-2">
              <img
                v-if="game.regionMeta?.icon"
                :src="game.regionMeta.icon"
                :alt="game.regionMeta.name"
                :title="game.regionMeta.name"
                class="w-6 border border-zinc-700 shrink-0"
              />
              <span class="text-xs text-zinc-500">{{ formatSize(game.file_size) }}</span>
            </div>
          </div>
        </Link>
      </li>
    </ul>

  </div>
</template>
