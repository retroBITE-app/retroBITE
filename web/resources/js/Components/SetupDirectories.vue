<script setup lang="ts">
import { ref, computed } from 'vue'

type Dir = { value: string; label: string }
type ConsoleEntry = { key: string; name: string; uploadDirs: Dir[] }

const props = defineProps<{
  consoles: ConsoleEntry[]
  title?: string
}>()

const emit = defineEmits<{ done: [] }>()

const open     = ref(false)
const creating = ref(false)
const error    = ref<string | null>(null)

// Record<consoleKey, string[]> — immutably updated so Vue tracks changes
const selected = ref<Record<string, string[]>>({})

const consolesWithDirs = computed(() =>
  props.consoles.filter(c => c.uploadDirs.length > 0)
)

const totalSelected = computed(() =>
  Object.values(selected.value).reduce((sum, list) => sum + list.length, 0)
)

function openModal() {
  selected.value = Object.fromEntries(
    props.consoles.map(c => [c.key, c.uploadDirs.map(d => d.value)])
  )
  error.value = null
  open.value  = true
}

function closeModal() {
  if (creating.value) return
  open.value = false
}

function toggle(consoleKey: string, value: string) {
  const list = selected.value[consoleKey] ?? []
  const idx  = list.indexOf(value)
  selected.value = {
    ...selected.value,
    [consoleKey]: idx >= 0
      ? list.filter((_, i) => i !== idx)
      : [...list, value],
  }
}

async function create() {
  if (totalSelected.value === 0) return

  creating.value = true
  error.value    = null

  const errors: string[] = []

  for (const c of consolesWithDirs.value) {
    const subs = selected.value[c.key] ?? []
    if (!subs.length) continue

    const form = new URLSearchParams()
    subs.forEach(sub => form.append('subfolders[]', sub))

    try {
      const res = await fetch(`/consoles/${c.key}/mkdir`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: form.toString(),
      })

      if (!res.ok) {
        const body = await res.json().catch(() => ({}))
        errors.push(`${c.name}: ${(body as { error?: string }).error ?? `HTTP ${res.status}`}`)
      }
    } catch {
      errors.push(`${c.name}: network error`)
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
    <div
      v-if="open"
      class="fixed inset-0 z-50 flex items-center justify-center p-4"
    >
      <div class="absolute inset-0 bg-black/60" @click="closeModal" />

      <div class="relative z-10 w-full max-w-lg rounded-xl bg-zinc-900 border border-zinc-700 shadow-2xl">

        <!-- Header -->
        <div class="flex items-center justify-between px-6 py-4 border-b border-zinc-800">
          <h2 class="text-base font-semibold text-zinc-100">{{ title ?? 'Create directories' }}</h2>
          <button
            @click="closeModal"
            :disabled="creating"
            class="text-zinc-500 hover:text-zinc-300 transition-colors disabled:opacity-40"
          >✕</button>
        </div>

        <div class="p-6 space-y-4">

          <!-- Empty state: all dirs already exist -->
          <div
            v-if="consolesWithDirs.length === 0"
            class="rounded-lg border border-dashed border-zinc-700 py-10 text-center"
          >
            <p class="text-zinc-500 text-sm">All directories already exist.</p>
          </div>

          <template v-else>
            <p class="text-xs text-zinc-400">Select which directories to create on disk.</p>

            <div class="max-h-96 overflow-y-auto space-y-4 pr-1">
              <div v-for="c in consolesWithDirs" :key="c.key">
                <p class="text-xs font-semibold text-zinc-500 uppercase tracking-wider mb-1 px-1">{{ c.name }}</p>
                <div class="space-y-0.5">
                  <label
                    v-for="dir in c.uploadDirs"
                    :key="dir.value"
                    class="flex items-center gap-3 rounded-md px-3 py-2 cursor-pointer transition-colors"
                    :class="selected[c.key]?.includes(dir.value) ? 'bg-zinc-700 text-zinc-100' : 'text-zinc-400 hover:bg-zinc-800'"
                  >
                    <input
                      type="checkbox"
                      :checked="selected[c.key]?.includes(dir.value)"
                      @change="toggle(c.key, dir.value)"
                      :disabled="creating"
                      class="accent-emerald-500"
                    />
                    <span class="font-mono text-sm">{{ dir.label }}</span>
                  </label>
                </div>
              </div>
            </div>
          </template>

          <p v-if="error" class="text-xs text-red-400">{{ error }}</p>
        </div>

        <!-- Footer -->
        <div class="flex items-center justify-end gap-2 px-6 py-4 border-t border-zinc-800">
          <button
            @click="closeModal"
            :disabled="creating"
            class="px-4 py-2 rounded-md text-sm text-zinc-400 hover:text-zinc-200 transition-colors disabled:opacity-40"
          >Cancel</button>
          <button
            v-if="consolesWithDirs.length > 0"
            @click="create"
            :disabled="totalSelected === 0 || creating"
            class="px-4 py-2 rounded-md text-sm font-medium bg-emerald-600 hover:bg-emerald-500 text-white transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {{ creating ? 'Creating…' : `Create ${totalSelected} director${totalSelected === 1 ? 'y' : 'ies'}` }}
          </button>
        </div>

      </div>
    </div>
  </Teleport>
</template>
