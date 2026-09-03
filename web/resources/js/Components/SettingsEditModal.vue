<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { apiHeaders } from '@/Helpers/http'
import { route } from '@/routes'

type FieldMeta = {
  type:      'text' | 'number' | 'text[]'
  label?:    string
  required?: boolean
}

type Schema = Record<string, FieldMeta>

const props = defineProps<{
  open:    boolean
  title:   string
  group:   string
  itemKey: string
  schema:  Schema
  value:   Record<string, unknown>
}>()

const emit = defineEmits<{
  close: []
  saved: []
}>()

const draft     = ref<Record<string, unknown>>({})
const saving    = ref(false)
const error     = ref<string | null>(null)
const fieldErrs = ref<Record<string, string>>({})

const fields = computed(() =>
  Object.entries(props.schema).map(([key, meta]) => ({ key, ...meta }))
)

watch(() => props.open, (isOpen) => {
  if (!isOpen) return

  const seed: Record<string, unknown> = {}
  for (const [key, meta] of Object.entries(props.schema)) {
    const v = props.value?.[key]
    seed[key] =
      meta.type === 'text[]' ? (Array.isArray(v) ? [...v] : [])
      : meta.type === 'number' ? (typeof v === 'number' ? v : '')
      : (v ?? '')
  }
  draft.value     = seed
  error.value     = null
  fieldErrs.value = {}
  saving.value    = false
})

function addListItem(field: string) {
  const current = (draft.value[field] as string[] | undefined) ?? []
  draft.value[field] = [...current, '']
}

function updateListItem(field: string, idx: number, val: string) {
  const current = (draft.value[field] as string[] | undefined) ?? []
  const next    = [...current]
  next[idx]     = val
  draft.value[field] = next
}

function removeListItem(field: string, idx: number) {
  const current = (draft.value[field] as string[] | undefined) ?? []
  draft.value[field] = current.filter((_, i) => i !== idx)
}

async function save() {
  if (saving.value) return

  saving.value    = true
  error.value     = null
  fieldErrs.value = {}

  // Strip empty strings from text[] lists before submit
  const payload: Record<string, unknown> = {}
  for (const [key, meta] of Object.entries(props.schema)) {
    const v = draft.value[key]
    if (meta.type === 'text[]') {
      payload[key] = Array.isArray(v)
        ? v.map(x => String(x).trim()).filter(x => x !== '')
        : []
    } else if (meta.type === 'number') {
      payload[key] = v === '' || v === null ? null : Number(v)
    } else {
      payload[key] = v === null || v === undefined ? '' : String(v)
    }
  }

  try {
    const res = await fetch(route('settings.save', { group: props.group, key: props.itemKey }), {
      method:  'POST',
      headers: {
        'Content-Type':     'application/json',
        ...apiHeaders(),
      },
      body: JSON.stringify(payload),
    })
    const body = await res.json().catch(() => ({}))
    if (!res.ok) {
      if (res.status === 422 && body.errors) {
        fieldErrs.value = body.errors
      }
      throw new Error(body.error ?? `HTTP ${res.status}`)
    }
    emit('saved')
    emit('close')
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Save failed'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-black/60" @click="saving ? null : emit('close')" />

      <div class="relative z-10 w-full max-w-xl rounded-xl bg-zinc-900 border border-zinc-700 shadow-2xl flex flex-col max-h-[90vh]">

        <!-- Header -->
        <div class="flex items-center justify-between px-6 py-4 border-b border-zinc-800">
          <div>
            <h2 class="text-base font-semibold text-zinc-100">Edit {{ title }}</h2>
            <p class="text-xs font-mono text-zinc-500 mt-0.5">{{ group }}.{{ itemKey }}</p>
          </div>
          <button
            @click="emit('close')"
            :disabled="saving"
            class="text-zinc-500 hover:text-zinc-300 transition-colors disabled:opacity-40"
          >✕</button>
        </div>

        <!-- Body -->
        <div class="p-6 space-y-4 overflow-y-auto">
          <div v-for="f in fields" :key="f.key">
            <label class="block text-xs uppercase tracking-wider text-zinc-500 mb-1.5">
              {{ f.label ?? f.key }}<span v-if="f.required" class="text-red-400 ml-0.5">*</span>
            </label>

            <!-- text -->
            <input
              v-if="f.type === 'text'"
              type="text"
              :value="draft[f.key] ?? ''"
              @input="draft[f.key] = ($event.target as HTMLInputElement).value"
              :disabled="saving"
              class="w-full rounded-md bg-zinc-800 border px-3 py-2 text-sm text-zinc-100 placeholder-zinc-600 outline-none transition-colors"
              :class="fieldErrs[f.key] ? 'border-red-500/60' : 'border-zinc-700 focus:border-emerald-500/60'"
            />

            <!-- number -->
            <input
              v-else-if="f.type === 'number'"
              type="number"
              :value="draft[f.key] ?? ''"
              @input="draft[f.key] = ($event.target as HTMLInputElement).value"
              :disabled="saving"
              class="w-full rounded-md bg-zinc-800 border px-3 py-2 text-sm text-zinc-100 placeholder-zinc-600 outline-none transition-colors"
              :class="fieldErrs[f.key] ? 'border-red-500/60' : 'border-zinc-700 focus:border-emerald-500/60'"
            />

            <!-- text[] -->
            <div v-else-if="f.type === 'text[]'" class="space-y-1.5">
              <div
                v-for="(item, idx) in (draft[f.key] as string[] | undefined) ?? []"
                :key="idx"
                class="flex items-center gap-2"
              >
                <input
                  type="text"
                  :value="item"
                  @input="updateListItem(f.key, idx, ($event.target as HTMLInputElement).value)"
                  :disabled="saving"
                  class="flex-1 rounded-md bg-zinc-800 border border-zinc-700 focus:border-emerald-500/60 px-3 py-1.5 text-sm font-mono text-zinc-100 outline-none"
                />
                <button
                  type="button"
                  @click="removeListItem(f.key, idx)"
                  :disabled="saving"
                  class="shrink-0 w-8 h-8 rounded-md text-zinc-500 hover:text-red-400 hover:bg-zinc-800 transition-colors disabled:opacity-40"
                  title="Remove"
                >✕</button>
              </div>
              <button
                type="button"
                @click="addListItem(f.key)"
                :disabled="saving"
                class="text-xs text-emerald-400 hover:text-emerald-300 transition-colors disabled:opacity-40"
              >+ Add</button>
            </div>

            <p v-if="fieldErrs[f.key]" class="mt-1 text-xs text-red-400">{{ fieldErrs[f.key] }}</p>
          </div>

          <p v-if="error" class="text-sm text-red-400">{{ error }}</p>
        </div>

        <!-- Footer -->
        <div class="flex items-center justify-end gap-2 px-6 py-4 border-t border-zinc-800">
          <button
            @click="emit('close')"
            :disabled="saving"
            class="px-4 py-2 rounded-md text-sm text-zinc-400 hover:text-zinc-200 transition-colors disabled:opacity-40"
          >Cancel</button>
          <button
            @click="save"
            :disabled="saving"
            class="px-4 py-2 rounded-md text-sm font-medium bg-emerald-600 hover:bg-emerald-500 text-white transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          >{{ saving ? 'Saving…' : 'Save' }}</button>
        </div>

      </div>
    </div>
  </Teleport>
</template>
