<script setup lang="ts">
import { ref, computed } from 'vue'
import { apiHeaders } from '@/Helpers/http'

const props = defineProps<{
  console:     string
  consoleName: string
  folder:      string
  title?:      string
}>()

const emit = defineEmits<{ done: [] }>()

const NAME_RE = /^[a-zA-Z0-9_\-]+(\/[a-zA-Z0-9_\-]+)*$/

const open     = ref(false)
const creating = ref(false)
const error    = ref<string | null>(null)
const rows     = ref<string[]>([''])

const cleanRows = computed(() => rows.value.map(r => r.trim()).filter(r => r !== ''))

const allValid = computed(() => cleanRows.value.length > 0 && cleanRows.value.every(r => NAME_RE.test(r)))

function openModal() {
  rows.value  = ['']
  error.value = null
  open.value  = true
}

function closeModal() {
  if (creating.value) return
  open.value = false
}

function addRow() {
  rows.value = [...rows.value, '']
}

function removeRow(idx: number) {
  rows.value = rows.value.length === 1 ? [''] : rows.value.filter((_, i) => i !== idx)
}

function updateRow(idx: number, val: string) {
  const next = [...rows.value]
  next[idx]  = val
  rows.value = next
}

function rowInvalid(v: string): boolean {
  const t = v.trim()
  return t !== '' && !NAME_RE.test(t)
}

async function create() {
  if (!allValid.value) return

  creating.value = true
  error.value    = null

  try {
    const form = new URLSearchParams()
    for (const r of cleanRows.value) {
      form.append('subfolders[]', r)
    }

    const res = await fetch(`/consoles/${props.console}/mkdir`, {
      method:  'POST',
      headers: {
        'Content-Type':     'application/x-www-form-urlencoded',
        ...apiHeaders(),
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
    error.value = e instanceof Error ? e.message : 'Failed to create directory'
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
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-black/60" @click="closeModal" />

      <div class="relative z-10 w-full max-w-md rounded-xl bg-zinc-900 border border-zinc-700 shadow-2xl">

        <div class="flex items-center justify-between px-6 py-4 border-b border-zinc-800">
          <h2 class="text-base font-semibold text-zinc-100">{{ title ?? 'New directory' }} — {{ consoleName }}</h2>
          <button
            @click="closeModal"
            :disabled="creating"
            class="text-zinc-500 hover:text-zinc-300 transition-colors disabled:opacity-40"
          >✕</button>
        </div>

        <div class="p-6 space-y-4">

          <p class="text-xs text-zinc-500">
            Create one or more folders under <span class="font-mono text-zinc-400">{{ folder }}/</span>.
            Use <span class="font-mono">nested/path</span> for sub-subfolders.
          </p>

          <div class="space-y-2">
            <div
              v-for="(row, idx) in rows"
              :key="idx"
              class="flex items-center gap-2"
            >
              <div
                class="flex-1 flex items-center rounded-md overflow-hidden border"
                :class="rowInvalid(row) ? 'border-red-500/70' : 'border-zinc-700'"
              >
                <span class="px-3 py-2 text-sm font-mono text-zinc-500 bg-zinc-800 border-r border-zinc-700 shrink-0">
                  {{ folder }}/
                </span>
                <input
                  :value="row"
                  @input="updateRow(idx, ($event.target as HTMLInputElement).value)"
                  type="text"
                  placeholder="folder-name"
                  :disabled="creating"
                  class="flex-1 bg-zinc-800 px-3 py-2 text-sm font-mono text-zinc-100 placeholder-zinc-600 outline-none disabled:opacity-50"
                />
              </div>
              <button
                type="button"
                @click="removeRow(idx)"
                :disabled="creating"
                class="shrink-0 w-8 h-8 rounded-md text-zinc-500 hover:text-red-400 hover:bg-zinc-800 transition-colors disabled:opacity-40"
                title="Remove"
              >✕</button>
            </div>
          </div>

          <button
            type="button"
            @click="addRow"
            :disabled="creating"
            class="text-xs text-emerald-400 hover:text-emerald-300 transition-colors disabled:opacity-40"
          >+ Add folder</button>

          <p v-if="rows.some(rowInvalid)" class="text-xs text-red-400">
            Only letters, numbers, hyphens, underscores, and slashes (for nesting) allowed.
          </p>
          <p v-if="error" class="text-xs text-red-400">{{ error }}</p>
        </div>

        <div class="flex items-center justify-end gap-2 px-6 py-4 border-t border-zinc-800">
          <button
            @click="closeModal"
            :disabled="creating"
            class="px-4 py-2 rounded-md text-sm text-zinc-400 hover:text-zinc-200 transition-colors disabled:opacity-40"
          >Cancel</button>
          <button
            @click="create"
            :disabled="!allValid || creating"
            class="px-4 py-2 rounded-md text-sm font-medium bg-emerald-600 hover:bg-emerald-500 text-white transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {{ creating ? 'Creating…' : (cleanRows.length > 1 ? `Create ${cleanRows.length} folders` : 'Create folder') }}
          </button>
        </div>

      </div>
    </div>
  </Teleport>
</template>
