<script setup lang="ts">
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps<{
  open:         boolean
  console:      string
  gameFileName: string
}>()

const emit = defineEmits<{ close: [] }>()

type Candidate = {
  provider_id: string
  title:       string | null
  rom_name:    string | null
  region:      string | null
  year:        string | null
  cover_url:   string | null
}

type Md5Match = {
  provider_id:  string
  title:        string | null
  cover_url:    string | null
  logo_url:     string | null
  backdrop_url: string | null
  release_date: string | null
  genre:        string | null
  developer:    string | null
  publisher:    string | null
  players:      string | null
} | null

const loading    = ref(false)
const error      = ref<string | null>(null)
const md5Match   = ref<Md5Match>(null)
const candidates = ref<Candidate[]>([])
const assigning  = ref<string | null>(null)

async function load() {
  loading.value    = true
  error.value      = null
  md5Match.value   = null
  candidates.value = []

  try {
    const url = `/consoles/${props.console}/${encodeURIComponent(props.gameFileName)}/identify`
    const res = await fetch(url, {
      method:  'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
    const body = await res.json().catch(() => ({}))
    if (!res.ok) throw new Error(body.error ?? `HTTP ${res.status}`)

    md5Match.value   = body.md5Match ?? null
    candidates.value = body.candidates ?? []
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Lookup failed'
  } finally {
    loading.value = false
  }
}

async function pick(providerId: string, source: 'md5' | 'name') {
  if (assigning.value) return

  assigning.value = `${source}:${providerId}`
  error.value     = null

  try {
    const url = `/consoles/${props.console}/${encodeURIComponent(props.gameFileName)}/metadata`
    const res = await fetch(url, {
      method:  'POST',
      headers: {
        'Content-Type':     'application/x-www-form-urlencoded',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: `provider_id=${encodeURIComponent(providerId)}`,
    })
    const body = await res.json().catch(() => ({}))
    if (!res.ok) throw new Error(body.error ?? `HTTP ${res.status}`)

    emit('close')
    router.reload({ only: ['game'] })
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Assign failed'
  } finally {
    assigning.value = null
  }
}

watch(() => props.open, (isOpen) => {
  if (isOpen) load()
})
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-black/60" @click="assigning === null && emit('close')" />

      <div class="relative z-10 w-full max-w-2xl rounded-xl bg-zinc-900 border border-zinc-700 shadow-2xl">

        <!-- Header -->
        <div class="flex items-center justify-between px-6 py-4 border-b border-zinc-800">
          <div>
            <h2 class="text-base font-semibold text-zinc-100">Identify game</h2>
            <p class="text-xs font-mono text-zinc-500 truncate mt-0.5 max-w-lg">{{ gameFileName }}</p>
          </div>
          <button
            @click="emit('close')"
            :disabled="assigning !== null"
            class="text-zinc-500 hover:text-zinc-300 transition-colors disabled:opacity-40"
          >✕</button>
        </div>

        <!-- Body -->
        <div class="p-6 space-y-5 max-h-[70vh] overflow-y-auto">

          <!-- Loading -->
          <div v-if="loading" class="flex items-center justify-center gap-3 text-zinc-400 text-sm py-8">
            <span class="inline-block h-5 w-5 rounded-full border-2 border-zinc-700 border-t-emerald-500 animate-spin"></span>
            <span>Searching ScreenScraper…</span>
          </div>

          <!-- Error -->
          <div v-else-if="error" class="rounded-md border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-400">
            {{ error }}
          </div>

          <!-- Empty -->
          <div v-else-if="!md5Match && candidates.length === 0" class="text-center py-10">
            <p class="text-zinc-300 text-sm">No matches found for this ROM.</p>
            <p class="text-zinc-600 text-xs mt-1">Try renaming the file to a cleaner title.</p>
          </div>

          <!-- Results -->
          <template v-else>

            <!-- MD5 match pinned -->
            <section v-if="md5Match">
              <div class="flex items-center gap-2 mb-2">
                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 shadow-[0_0_6px_var(--color-emerald-500)]"></span>
                <h3 class="text-[11px] font-semibold uppercase tracking-wider text-emerald-500">Exact MD5 match</h3>
              </div>
              <button
                @click="pick(md5Match.provider_id, 'md5')"
                :disabled="assigning !== null"
                class="w-full rounded-lg border border-emerald-500/30 bg-emerald-500/5 hover:bg-emerald-500/10 hover:border-emerald-500/60 transition-colors p-3 flex gap-3 text-left disabled:opacity-50 disabled:cursor-not-allowed"
              >
                <img v-if="md5Match.cover_url" :src="md5Match.cover_url" class="w-14 h-20 object-cover rounded bg-zinc-800 shrink-0" />
                <div v-else class="w-14 h-20 rounded bg-zinc-800 shrink-0" />
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-zinc-100 truncate">{{ md5Match.title ?? '(no title)' }}</p>
                  <p class="text-xs text-zinc-500 mt-0.5">
                    <span v-if="md5Match.release_date">{{ md5Match.release_date }}</span>
                    <span v-if="md5Match.genre" class="ml-2">· {{ md5Match.genre }}</span>
                  </p>
                  <p class="text-xs text-zinc-600 mt-0.5 truncate">
                    <span v-if="md5Match.developer">{{ md5Match.developer }}</span>
                    <span v-if="md5Match.publisher && md5Match.publisher !== md5Match.developer" class="ml-1">/ {{ md5Match.publisher }}</span>
                  </p>
                </div>
                <span v-if="assigning === `md5:${md5Match.provider_id}`" class="shrink-0 self-center text-xs text-emerald-400">Assigning…</span>
              </button>
            </section>

            <!-- Name matches -->
            <section v-if="candidates.length">
              <h3 class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500 mb-2">
                Name matches <span class="text-zinc-600 font-normal">({{ candidates.length }})</span>
              </h3>
              <div class="space-y-1.5">
                <button
                  v-for="c in candidates"
                  :key="c.provider_id"
                  @click="pick(c.provider_id, 'name')"
                  :disabled="assigning !== null"
                  class="w-full rounded-lg border border-zinc-700 bg-zinc-800/50 hover:border-zinc-500 hover:bg-zinc-800 transition-colors p-3 flex gap-3 text-left disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  <img v-if="c.cover_url" :src="c.cover_url" class="w-12 h-16 object-cover rounded bg-zinc-800 shrink-0" />
                  <div v-else class="w-12 h-16 rounded bg-zinc-800 shrink-0" />
                  <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-zinc-100 truncate">{{ c.title ?? '(no title)' }}</p>
                    <p class="text-xs text-zinc-500 mt-0.5">
                      <span v-if="c.year">{{ c.year }}</span>
                      <span v-if="c.region" class="ml-2">· {{ c.region }}</span>
                    </p>
                    <p v-if="c.rom_name" class="text-xs font-mono text-zinc-600 truncate mt-0.5">{{ c.rom_name }}</p>
                  </div>
                  <span v-if="assigning === `name:${c.provider_id}`" class="shrink-0 self-center text-xs text-emerald-400">Assigning…</span>
                </button>
              </div>
            </section>

          </template>
        </div>

        <!-- Footer -->
        <div class="flex items-center justify-between px-6 py-4 border-t border-zinc-800">
          <p class="text-xs text-zinc-600">Powered by ScreenScraper.fr</p>
          <div class="flex items-center gap-2">
            <button
              v-if="!loading && !error"
              @click="load"
              :disabled="assigning !== null"
              class="px-3 py-1.5 rounded-md text-xs text-zinc-400 hover:text-zinc-200 transition-colors disabled:opacity-40"
            >Refresh</button>
            <button
              @click="emit('close')"
              :disabled="assigning !== null"
              class="px-4 py-2 rounded-md text-sm text-zinc-400 hover:text-zinc-200 transition-colors disabled:opacity-40"
            >Close</button>
          </div>
        </div>

      </div>
    </div>
  </Teleport>
</template>
