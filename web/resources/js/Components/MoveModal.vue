<script setup lang="ts">
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps<{
  open:        boolean
  console:     string
  gameFileName: string
  folder:      string
  folderLabel: string
  folders:     Array<{ value: string; label: string }>
}>()

const emit     = defineEmits<{ close: [] }>()

const selected = ref<string | null>(null)
const busy     = ref(false)
const error    = ref<string | null>(null)

watch(() => props.open, (isOpen) => {
  if (!isOpen) return
  selected.value = null
  error.value    = null
  busy.value     = false
})

async function submit() {

  if (selected.value === null || busy.value) return

  busy.value  = true
  error.value = null

  try {
    const url  = `/consoles/${props.console}/${encodeURIComponent(props.gameFileName)}/move`
    const form = new URLSearchParams({ subfolder: selected.value })

    const res = await fetch(url, {
      method:  'POST',
      headers: {
        'Content-Type':     'application/x-www-form-urlencoded',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: form.toString(),
    })
    const body = await res.json().catch(() => ({}))
    if (!res.ok) throw new Error(body.error ?? `HTTP ${res.status}`)

    emit('close')

    router.reload({ only: ['folder', 'folderLabel'] })
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Move failed'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-black/60" @click="!busy && emit('close')" />

      <div class="relative z-10 w-full max-w-md rounded-xl bg-zinc-900 border border-zinc-700 shadow-2xl">

        <!-- Header -->
        <div class="flex items-start justify-between gap-4 px-6 py-4 border-b border-zinc-800">
          <div class="min-w-0">
            <h2 class="text-base font-semibold text-zinc-100">Move file</h2>
            <p class="text-xs font-mono text-zinc-500 truncate mt-0.5">{{ gameFileName }}</p>
          </div>
          <button
            @click="emit('close')"
            :disabled="busy"
            class="shrink-0 cursor-pointer text-zinc-500 hover:text-zinc-300 transition-colors disabled:opacity-40"
          >✕</button>
        </div>

        <!-- Body -->
        <div class="px-6 py-5 space-y-4">
          <!-- Read from the prop, never looked up in `folders` — a nested current
               folder is not in that list but must still display correctly. -->
          <p class="text-sm text-zinc-400">
            Currently in <span class="font-mono text-zinc-200">{{ folderLabel }}</span>
          </p>

          <div>
            <p class="text-xs font-medium text-zinc-400 mb-2">Move to</p>
            <div class="space-y-1">
              <label
                v-for="dir in folders"
                :key="dir.value"
                class="flex items-center gap-3 rounded-md px-3 py-2 transition-colors"
                :class="dir.value === folder
                  ? 'text-zinc-600 cursor-not-allowed'
                  : selected === dir.value
                    ? 'bg-zinc-700 text-zinc-100 cursor-pointer'
                    : 'text-zinc-400 hover:bg-zinc-800 cursor-pointer'"
              >
                <input
                  type="radio"
                  :value="dir.value"
                  v-model="selected"
                  :disabled="busy || dir.value === folder"
                  class="accent-emerald-500"
                />
                <span class="font-mono text-sm">{{ dir.label }}</span>
                <span v-if="dir.value === folder" class="text-xs">(current)</span>
              </label>
            </div>
          </div>

          <div
            v-if="error"
            class="rounded-md border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-400"
          >
            {{ error }}
          </div>
        </div>

        <!-- Footer -->
        <div class="flex items-center justify-end gap-2 px-6 py-4 border-t border-zinc-800">
          <button
            @click="emit('close')"
            :disabled="busy"
            class="px-4 py-2 cursor-pointer rounded-md text-sm text-zinc-400 hover:text-zinc-200 transition-colors disabled:opacity-40"
          >Cancel</button>
          <button
            @click="submit"
            :disabled="selected === null || busy"
            class="px-4 py-2 cursor-pointer rounded-md text-sm font-medium bg-emerald-600 hover:bg-emerald-500 text-white transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          >{{ busy ? 'Moving…' : 'Move' }}</button>
        </div>

      </div>
    </div>
  </Teleport>
</template>
