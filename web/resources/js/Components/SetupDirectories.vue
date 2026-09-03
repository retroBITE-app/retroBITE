<script setup lang="ts">
import { ref, computed } from 'vue'
import { apiHeaders } from '@/Helpers/http'

type ConsoleEntry = { key: string; name: string }

const props = defineProps<{
  consoles: ConsoleEntry[]
  title?:   string
}>()

const emit = defineEmits<{ done: [] }>()

const open     = ref(false)
const creating = ref(false)
const error    = ref<string | null>(null)
const picked   = ref<Set<string>>(new Set())

const selectedCount = computed(() => picked.value.size)

function openModal() {
  picked.value = new Set()
  error.value  = null
  open.value   = true
}

function closeModal() {
  if (creating.value) return
  open.value = false
}

function toggle(consoleKey: string) {
  const next = new Set(picked.value)
  next.has(consoleKey) ? next.delete(consoleKey) : next.add(consoleKey)
  picked.value = next
}

async function create() {
  if (selectedCount.value === 0) return

  creating.value = true
  error.value    = null

  const errors: string[] = []

  for (const key of picked.value) {
    const form = new URLSearchParams()
    form.append('subfolders[]', '') // empty → create root folder

    try {
      const res = await fetch(`/consoles/${key}/mkdir`, {
        method:  'POST',
        headers: {
          'Content-Type':     'application/x-www-form-urlencoded',
          ...apiHeaders(),
        },
        body: form.toString(),
      })

      if (!res.ok) {
        const body = await res.json().catch(() => ({}))
        const name = props.consoles.find(c => c.key === key)?.name ?? key
        errors.push(`${name}: ${(body as { error?: string }).error ?? `HTTP ${res.status}`}`)
      }
    } catch {
      const name = props.consoles.find(c => c.key === key)?.name ?? key
      errors.push(`${name}: network error`)
    }
  }

  creating.value = false

  if (errors.length) {
    error.value = errors.join(' · ')
  } else {
    open.value = false
    emit('done')
  }
}
</script>

<template>
  <button
    @click="openModal"
    class="px-4 py-2 rounded-md text-sm font-medium bg-zinc-700 hover:bg-zinc-600 text-white transition-colors"
  >
    New console
  </button>

  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-black/60" @click="closeModal" />

      <div class="relative z-10 w-full max-w-lg rounded-xl bg-zinc-900 border border-zinc-700 shadow-2xl">

        <div class="flex items-center justify-between px-6 py-4 border-b border-zinc-800">
          <h2 class="text-base font-semibold text-zinc-100">{{ title ?? 'Install console' }}</h2>
          <button
            @click="closeModal"
            :disabled="creating"
            class="text-zinc-500 hover:text-zinc-300 transition-colors disabled:opacity-40"
          >✕</button>
        </div>

        <div class="p-6 space-y-4">

          <div
            v-if="consoles.length === 0"
            class="rounded-lg border border-dashed border-zinc-700 py-10 text-center"
          >
            <p class="text-zinc-500 text-sm">Every console is already installed.</p>
          </div>

          <template v-else>
            <p class="text-xs text-zinc-400">
              Pick one or more consoles to install. Folders get created under the games path;
              you can add subfolders later via the console page.
            </p>

            <div class="max-h-96 overflow-y-auto space-y-1 pr-1">
              <label
                v-for="c in consoles"
                :key="c.key"
                class="flex items-center gap-3 rounded-md px-3 py-2 cursor-pointer transition-colors"
                :class="picked.has(c.key) ? 'bg-zinc-700 text-zinc-100' : 'text-zinc-400 hover:bg-zinc-800'"
              >
                <input
                  type="checkbox"
                  :checked="picked.has(c.key)"
                  @change="toggle(c.key)"
                  :disabled="creating"
                  class="sr-only"
                />
                <span class="text-sm">{{ c.name }}</span>
                <span class="ml-auto text-xs font-mono text-zinc-600">{{ c.key }}/</span>
              </label>
            </div>
          </template>

          <p v-if="error" class="text-xs text-red-400">{{ error }}</p>
        </div>

        <div class="flex items-center justify-end gap-2 px-6 py-4 border-t border-zinc-800">
          <button
            @click="closeModal"
            :disabled="creating"
            class="px-4 py-2 rounded-md text-sm text-zinc-400 hover:text-zinc-200 transition-colors disabled:opacity-40"
          >Cancel</button>
          <button
            v-if="consoles.length > 0"
            @click="create"
            :disabled="selectedCount === 0 || creating"
            class="px-4 py-2 rounded-md text-sm font-medium bg-emerald-600 hover:bg-emerald-500 text-white transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {{ creating ? 'Installing…' : `Install ${selectedCount} console${selectedCount === 1 ? '' : 's'}` }}
          </button>
        </div>

      </div>
    </div>
  </Teleport>
</template>
