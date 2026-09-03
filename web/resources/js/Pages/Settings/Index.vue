<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import SettingsEditModal from '@/Components/SettingsEditModal.vue'
import { apiHeaders } from '@/Helpers/http'
import { route } from '@/routes'

type FieldMeta = {
  type:      'text' | 'number' | 'text[]'
  label?:    string
  required?: boolean
}

type Group = {
  slug:      string
  label:     string
  schema:    Record<string, FieldMeta>
  items:     Record<string, Record<string, unknown>>
  overrides: string[]
}

const props = defineProps<{ groups: Group[] }>()

const activeSlug = ref<string>(props.groups[0]?.slug ?? '')
const activeGroup = computed(() => props.groups.find(g => g.slug === activeSlug.value) ?? null)

const editingKey = ref<string | null>(null)
const resetBusy  = ref<string | null>(null)
const resetError = ref<string | null>(null)

const editingItem = computed(() => {
  if (!editingKey.value || !activeGroup.value) return null
  return activeGroup.value.items[editingKey.value] ?? null
})

const editingLabel = computed(() => {
  const v = editingItem.value
  if (!v) return editingKey.value ?? ''
  const name = v.name
  return typeof name === 'string' && name ? name : (editingKey.value ?? '')
})

function isOverridden(slug: string, key: string): boolean {
  return props.groups.find(g => g.slug === slug)?.overrides.includes(key) ?? false
}

function openEdit(key: string) {
  editingKey.value = key
}

function closeEdit() {
  editingKey.value = null
}

function onSaved() {
  router.reload({ only: ['groups'] })
}

async function reset(slug: string, key: string) {
  if (resetBusy.value) return

  resetBusy.value  = `${slug}:${key}`
  resetError.value = null

  try {
    const res = await fetch(route('settings.reset', { group: slug, key }), {
      method:  'POST',
      headers: apiHeaders(),
    })
    const body = await res.json().catch(() => ({}))
    if (!res.ok) throw new Error(body.error ?? `HTTP ${res.status}`)
    router.reload({ only: ['groups'] })
  } catch (e: unknown) {
    resetError.value = e instanceof Error ? e.message : 'Reset failed'
  } finally {
    resetBusy.value = null
  }
}

function previewField(value: unknown): string {
  if (Array.isArray(value)) return value.join(', ') || '—'
  if (value === null || value === undefined || value === '') return '—'
  return String(value)
}

function itemPreviewFields(schema: Record<string, FieldMeta>): string[] {
  // Show a few informative fields in the card summary (skip image URLs)
  const skip = new Set(['icon', 'file_icon', 'name'])
  return Object.keys(schema).filter(k => !skip.has(k)).slice(0, 3)
}
</script>

<template>
  <div>
    <PageHeader>
      <template #title>
        <h1 class="text-2xl font-semibold text-zinc-100">Settings</h1>
      </template>
    </PageHeader>

    <!-- Tabs -->
    <div class="flex items-center gap-1 border-b border-zinc-800 mb-6">
      <button
        v-for="g in groups"
        :key="g.slug"
        @click="activeSlug = g.slug"
        :class="activeSlug === g.slug
          ? 'text-zinc-100 border-emerald-500'
          : 'text-zinc-500 border-transparent hover:text-zinc-300'"
        class="px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors cursor-pointer"
      >
        {{ g.label }}
        <span v-if="g.overrides.length" class="ml-1.5 text-xs text-emerald-400">({{ g.overrides.length }})</span>
      </button>
    </div>

    <p v-if="resetError" class="mb-4 rounded-md border border-red-500/30 bg-red-500/10 px-4 py-2 text-sm text-red-400">
      {{ resetError }}
    </p>

    <!-- Items grid -->
    <div v-if="activeGroup" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      <div
        v-for="(item, key) in activeGroup.items"
        :key="key"
        class="rounded-xl border border-zinc-700 bg-zinc-800/50 overflow-hidden flex flex-col"
      >
        <div class="px-5 py-4 border-b border-zinc-700/60 flex items-center justify-between gap-3">
          <div class="flex items-center gap-3 min-w-0">
            <img
              v-if="item.icon"
              :src="item.icon as string"
              :alt="(item.name as string) || key"
              class="h-8 w-8 object-contain opacity-80 shrink-0"
            />
            <div class="min-w-0">
              <p class="text-sm font-semibold text-zinc-100 truncate">
                {{ (item.name as string) || key }}
              </p>
              <p class="text-xs font-mono text-zinc-500 truncate">{{ key }}</p>
            </div>
          </div>
          <span
            v-if="isOverridden(activeGroup.slug, String(key))"
            class="shrink-0 rounded-md bg-emerald-500/10 text-emerald-400 text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 ring-1 ring-inset ring-emerald-500/20"
          >Overridden</span>
        </div>

        <dl class="px-5 py-3 space-y-1 text-xs flex-1">
          <div
            v-for="field in itemPreviewFields(activeGroup.schema)"
            :key="field"
            class="flex gap-2"
          >
            <dt class="text-zinc-500 shrink-0 w-28 uppercase tracking-wider">{{ activeGroup.schema[field].label ?? field }}</dt>
            <dd class="text-zinc-300 truncate font-mono">{{ previewField(item[field]) }}</dd>
          </div>
        </dl>

        <div class="px-5 py-3 border-t border-zinc-700/60 flex items-center justify-end gap-2">
          <button
            v-if="isOverridden(activeGroup.slug, String(key))"
            @click="reset(activeGroup.slug, String(key))"
            :disabled="resetBusy === `${activeGroup.slug}:${key}`"
            class="px-3 py-1.5 rounded-md text-xs text-zinc-400 hover:text-red-400 transition-colors disabled:opacity-40"
          >{{ resetBusy === `${activeGroup.slug}:${key}` ? 'Resetting…' : 'Reset' }}</button>
          <button
            @click="openEdit(String(key))"
            class="px-3 py-1.5 rounded-md text-xs font-medium bg-zinc-700 hover:bg-zinc-600 text-white transition-colors"
          >Edit</button>
        </div>
      </div>
    </div>

    <SettingsEditModal
      v-if="activeGroup"
      :open="editingKey !== null"
      :title="editingLabel"
      :group="activeGroup.slug"
      :item-key="editingKey ?? ''"
      :schema="activeGroup.schema"
      :value="editingItem ?? {}"
      @close="closeEdit"
      @saved="onSaved"
    />
  </div>
</template>
