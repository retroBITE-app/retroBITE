<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { formatSize } from '@/Helpers/format'
import PageHeader from '@/Components/PageHeader.vue'
import IdentifyModal from '@/Components/IdentifyModal.vue'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import AppLayout from '@/Layouts/AppLayout.vue'

defineOptions({
  layout: (h: typeof import('vue').h, page: any) => h(AppLayout, { noPadding: true }, () => page),
})

const props = defineProps<{
  console: string
  meta: { name: string; icon: string; file_icon: string }
  game: {
    file_name:      string
    file_size?:     number
    file_md5?:      string | null
    title?:         string
    description?:   string
    cover_url?:     string | null
    logo_url?:      string | null
    backdrop_url?:  string | null
    release_date?:  string | null
    genre?:         string | null
    players?:       string | null
    publisher?:     string | null
    developer?:     string | null
    first_seen_at?: number
    last_seen_at?:  number
    identified_at?: number | null
    region?:        string | null
    regionMeta?:    { name: string; flag: string; codes: string[]; icon: string } | null
  },
}>()

function formatDate(ts?: number): string {
  if (!ts) return '—'
  return new Date(ts * 1000).toLocaleDateString(undefined, {
    year: 'numeric', month: 'short', day: 'numeric',
  })
}

const ext = props.game.file_name.split('.').pop()?.toUpperCase() ?? '—'
const actionsOpen = ref(false)
const actionsRef = ref<HTMLElement | null>(null)
const identifyOpen = ref(false)
const deleteOpen = ref(false)
const deleting = ref(false)
const deleteError = ref<string | null>(null)

function openIdentify() {
  actionsOpen.value = false
  identifyOpen.value = true
}

function openDelete() {
  actionsOpen.value = false
  deleteError.value = null
  deleteOpen.value = true
}

async function confirmDelete() {
  deleting.value = true
  deleteError.value = null

  try {
    const url = `/consoles/${props.console}/${encodeURIComponent(props.game.file_name)}`
    const res = await fetch(url, {
      method:  'DELETE',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
    const body = await res.json().catch(() => ({}))
    if (!res.ok) throw new Error(body.error ?? `HTTP ${res.status}`)

    deleteOpen.value = false
    router.visit(`/consoles/${props.console}`)
  } catch (e: unknown) {
    deleteError.value = e instanceof Error ? e.message : 'Delete failed'
  } finally {
    deleting.value = false
  }
}

function onClickOutside(e: MouseEvent) {
  if (actionsRef.value && !actionsRef.value.contains(e.target as Node)) {
    actionsOpen.value = false
  }
}

onMounted(() => document.addEventListener('click', onClickOutside))
onUnmounted(() => document.removeEventListener('click', onClickOutside))
</script>

<template>
  <div class="flex flex-col gap-4">

    <div class="relative top-0 left-0 flex flex-col pb-10">

      <!-- Backdrop -->
      <div
        class="absolute inset-0 bg-cover bg-center min-h-125 bg-zinc-950"
        :style="game.backdrop_url ? { backgroundImage: `url(${game.backdrop_url})` } : {}"
      >
        <div v-if="game.backdrop_url" class="absolute inset-0 bg-black/60" />
        <div class="absolute inset-x-0 bottom-0 h-32 bg-linear-to-t from-zinc-950 to-transparent" />
      </div>

      <!-- Game header -->
      <div class="z-10 p-8">

        <!-- Header -->
        <PageHeader>
          <template #title>
            <div class="flex items-center gap-4">
              <Link :href="`/consoles/${props.console}`" class="text-white hover:text-zinc-300 transition-colors text-sm">←
                Back</Link>
            </div>
            <!-- Action button group -->
            <div class="flex items-center gap-2">
              <!-- Fetch Achievements -->
              <button class="inline-flex items-center cursor-pointer gap-1.5 px-3 py-1.5 rounded bg-zinc-700 hover:bg-zinc-600 text-white text-sm font-medium transition-colors">
                Fetch Achievements
              </button>

              <!-- Actions dropdown -->
              <div ref="actionsRef" class="relative">
                <button @click="actionsOpen = !actionsOpen" class="inline-flex items-center cursor-pointer gap-1.5 px-3 py-1.5 rounded bg-zinc-700 hover:bg-zinc-600 text-white text-sm font-medium transition-colors">
                  Actions
                  <span class="text-xs">▾</span>
                </button>
                <div v-show="actionsOpen" class="absolute right-0 mt-1 w-44 rounded border border-zinc-700 bg-zinc-800 shadow-lg z-50">
                  <!-- <button class="flex w-full cursor-pointer items-center gap-2 px-3 py-2 text-sm text-zinc-200 hover:bg-zinc-700 transition-colors">
                    Download
                  </button>
                  <button class="flex w-full cursor-pointer items-center gap-2 px-3 py-2 text-sm text-zinc-200 hover:bg-zinc-700 transition-colors">
                    Share
                  </button> -->
                  <!-- <div class="border-t border-zinc-700 my-0.5" /> -->
                  <button
                    @click="openIdentify"
                    class="flex w-full cursor-pointer items-center gap-2 px-3 py-2 text-sm text-zinc-200 hover:bg-zinc-700 transition-colors"
                  >
                    Identify
                  </button>
                  <!-- <button class="flex w-full cursor-pointer items-center gap-2 px-3 py-2 text-sm text-zinc-200 hover:bg-zinc-700 transition-colors">
                    Edit Meta
                  </button>
                  <button class="flex w-full cursor-pointer items-center gap-2 px-3 py-2 text-sm text-zinc-200 hover:bg-zinc-700 transition-colors">
                    Rename
                  </button>
                  -->
                  <div class="border-t border-zinc-700 my-0.5" />
                  <button
                    @click="openDelete"
                    class="flex w-full cursor-pointer items-center gap-2 px-3 py-2 text-sm text-red-400 hover:bg-zinc-700 transition-colors"
                  >
                    Delete
                  </button>
                </div>
              </div>
            </div>
          </template>
        </PageHeader>

        <!-- Game card -->
        <div class="flex gap-6 rounded-lg">

          <!-- Cover art -->
          <div class="shrink-0 w-64 min-h-88.75">
            <img v-if="game.cover_url" :src="game.cover_url" :alt="game.title"
              class="w-full h-auto object-contain rounded-md border border-zinc-600" />
            <div v-else
              class="w-full aspect-5/7 rounded-md border border-zinc-600 bg-zinc-900 flex items-center justify-center">
              <img :src="meta.file_icon" :alt="meta.name" class="w-34 h-34 object-contain opacity-30" />
            </div>
          </div>

          <!-- Details -->
          <div class="flex flex-col justify-start py-4 gap-3">

            <div class="flex items-center gap-3">
              <img v-if="game.logo_url" :src="game.logo_url" :alt="game.title" class="w-30" />
              <div>
                <p class="text-zinc-100 text-xl font-semibold">{{ game.title }}</p>
                <p class="text-zinc-500 text-sm mt-0.5 font-mono">{{ game.file_name }}</p>
              </div>
            </div>

            <!-- badges -->
            <div class="flex gap-2">
              <img :src="props.game.regionMeta?.icon" :alt="props.game.regionMeta?.name" class="w-8 border-2 border-zinc-700" />
              <span
                class="inline-flex px-2 pt-1 items-center rounded text-xs font-mono bg-zinc-700 text-white leading-none">{{
                meta.name }}</span>
              <span
                class="inline-flex px-2 pt-1 items-center rounded text-xs font-mono bg-zinc-700 text-white leading-none">{{
                  formatSize(game.file_size) }}</span>
              <span
                class="inline-flex px-2 pt-1 items-center rounded text-xs font-mono bg-zinc-700 text-white leading-none">{{
                ext }}</span>
              <span v-if="game.release_date"
                class="inline-flex px-2 pt-1 items-center rounded text-xs font-mono bg-zinc-700 text-white leading-none">{{
                  game.release_date }}</span>
              <span class="inline-flex px-2 pt-1 items-center rounded text-xs font-mono bg-zinc-700 text-white leading-none">
                {{ game.file_md5 ?? '—' }}
              </span>
            </div>

            <!-- Detail strip -->
            <dl
              v-if="game.developer || game.publisher || game.genre || game.players"
              class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-1 text-sm max-w-xl"
            >
              <template v-if="game.developer">
                <dt class="text-xs uppercase tracking-wider text-zinc-500 self-center">Developer</dt>
                <dd class="text-zinc-200">{{ game.developer }}</dd>
              </template>
              <template v-if="game.publisher">
                <dt class="text-xs uppercase tracking-wider text-zinc-500 self-center">Publisher</dt>
                <dd class="text-zinc-200">{{ game.publisher }}</dd>
              </template>
              <template v-if="game.genre">
                <dt class="text-xs uppercase tracking-wider text-zinc-500 self-center">Genre</dt>
                <dd class="text-zinc-200">{{ game.genre }}</dd>
              </template>
              <template v-if="game.players">
                <dt class="text-xs uppercase tracking-wider text-zinc-500 self-center">Players</dt>
                <dd class="text-zinc-200">{{ game.players }}</dd>
              </template>
            </dl>

            <div class="flex" v-if="game.description">
              {{ game.description }}
            </div>

            <div class="flex" v-else>
              This file is currently shy. Please identify file to get metadata - Powered by Retrobite
            </div>

          </div>
        </div>

      </div>
    </div>

    <div class="text-white relative z-10 p-8">
      new section here
    </div>

    <IdentifyModal
      :open="identifyOpen"
      :console="props.console"
      :game-file-name="game.file_name"
      @close="identifyOpen = false"
    />

    <ConfirmDialog
      :open="deleteOpen"
      title="Delete this file?"
      :message="`\u201C${game.file_name}\u201D will be permanently removed from storage, along with its metadata.`"
      warning="This cannot be undone. The file will be lost forever if you proceed."
      confirm-label="Delete forever"
      variant="danger"
      :busy="deleting"
      @confirm="confirmDelete"
      @cancel="deleteOpen = false"
    />

    <div v-if="deleteError" class="fixed bottom-4 right-4 z-50 max-w-sm rounded-md border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm text-red-300 shadow-lg">
      {{ deleteError }}
    </div>

  </div>
</template>