<script setup lang="ts">
import { ref, computed } from 'vue'

const props = defineProps<{
  console: string
  consoleName: string
  dirs: Array<{ value: string; label: string }>
  folder: string
  title?: string
}>()

const emit = defineEmits<{ done: [] }>()

const open      = ref(false)
const creating  = ref(false)
const error     = ref<string | null>(null)
const selected  = ref<string[]>([])
const customDir = ref('')

const customDirValid = computed(() =>
  customDir.value === '' || /^[a-zA-Z0-9_\-]+$/.test(customDir.value)
)

const allToCreate = computed(() => {
  const list = [...selected.value]
  const trimmed = customDir.value.trim()
  if (trimmed && !list.includes(trimmed)) list.push(trimmed)
  return list
})

function openModal() {
  selected.value  = props.dirs.map(d => d.value)
  customDir.value = ''
  error.value     = null
  open.value      = true
}

function closeModal() {
  if (creating.value) return
  open.value = false
}

function toggle(value: string) {
  const idx = selected.value.indexOf(value)
  selected.value = idx >= 0
    ? selected.value.filter((_, i) => i !== idx)
    : [...selected.value, value]
}

async function create() {
  if (!allToCreate.value.length || !customDirValid.value) return

  creating.value = true
  error.value    = null

  try {
    const form = new URLSearchParams()
    allToCreate.value.forEach(sub => form.append('subfolders[]', sub))

    const res = await fetch(`/consoles/${props.console}/mkdir`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: form.toString(),
    })

    if (!res.ok) {
      const body = await res.json().catch(() => ({}))
      throw new Error((body as { error?: string }).error ?? `HTTP ${res.status}`)
    }

    open.value = false
    emit('done')
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Failed to create directories'
  } finally {
    creating.value = false
  }
}
</script>

<template>
  <button
    @click="openModal"
    class="px-4 py-2 rounded-md text-sm font-medium bg-zinc-700 hover:bg-zinc-600 text-white transition-colors"
  >
    New directory
  </button>

  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-50 flex items-center justify-center p-4"
    >
      <div class="absolute inset-0 bg-black/60" @click="closeModal" />

      <div class="relative z-10 w-full max-w-md rounded-xl bg-zinc-900 border border-zinc-700 shadow-2xl">

        <!-- Header -->
        <div class="flex items-center justify-between px-6 py-4 border-b border-zinc-800">
          <h2 class="text-base font-semibold text-zinc-100">{{ title ?? 'Create directories' }} — {{ consoleName }}</h2>
          <button
            @click="closeModal"
            :disabled="creating"
            class="text-zinc-500 hover:text-zinc-300 transition-colors disabled:opacity-40"
          >✕</button>
        </div>

        <div class="p-6 space-y-5">

          <!-- Predefined dirs -->
          <div v-if="dirs.length">
            <p class="text-xs text-zinc-400 mb-2">Select which directories to create on disk.</p>
            <div class="space-y-1">
              <label
                v-for="dir in dirs"
                :key="dir.value"
                class="flex items-center gap-3 rounded-md px-3 py-2 cursor-pointer transition-colors"
                :class="selected.includes(dir.value) ? 'bg-zinc-700 text-zinc-100' : 'text-zinc-400 hover:bg-zinc-800'"
              >
                <input
                  type="checkbox"
                  :checked="selected.includes(dir.value)"
                  @change="toggle(dir.value)"
                  :disabled="creating"
                  class="accent-emerald-500"
                />
                <span class="font-mono text-sm">{{ dir.label }}</span>
              </label>
            </div>
          </div>

          <!-- Custom folder -->
          <div>
            <p class="text-xs text-zinc-400 mb-2">Custom folder</p>
            <div class="flex items-center gap-0 rounded-md overflow-hidden border"
              :class="customDirValid ? 'border-zinc-700' : 'border-red-500/70'"
            >
              <span class="px-3 py-2 text-sm font-mono text-zinc-500 bg-zinc-800 border-r border-zinc-700 shrink-0">
                {{ folder }}/
              </span>
              <input
                v-model="customDir"
                type="text"
                placeholder="folder-name"
                :disabled="creating"
                class="flex-1 bg-zinc-800 px-3 py-2 text-sm font-mono text-zinc-100 placeholder-zinc-600 outline-none disabled:opacity-50"
              />
            </div>
            <p v-if="!customDirValid" class="mt-1 text-xs text-red-400">
              Only letters, numbers, hyphens, and underscores allowed.
            </p>
          </div>

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
            @click="create"
            :disabled="!allToCreate.length || !customDirValid || creating"
            class="px-4 py-2 rounded-md text-sm font-medium bg-emerald-600 hover:bg-emerald-500 text-white transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {{ creating ? 'Creating…' : `Create ${allToCreate.length} director${allToCreate.length === 1 ? 'y' : 'ies'}` }}
          </button>
        </div>

      </div>
    </div>
  </Teleport>
</template>
