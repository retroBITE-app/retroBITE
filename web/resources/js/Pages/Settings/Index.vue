<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import SettingsEditModal from '@/Components/SettingsEditModal.vue'
import AlertBox from '@/Components/UI/AlertBox.vue'
import BaseButton from '@/Components/UI/BaseButton.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import { useApiAction } from '@/Composables/useApiAction'
import { route } from '@/routes'
import type { FieldMeta, SettingsGroup } from '@/Types/api'

/** Fields the card summary skips: the title, and anything that is an image URL. */
const SUMMARY_SKIP = new Set(['icon', 'file_icon', 'name'])

/** How many fields a card summarises. */
const SUMMARY_LIMIT = 3

const props = defineProps<{ groups: SettingsGroup[] }>()

const activeSlug = ref(props.groups[0]?.slug ?? '')
const editingKey = ref<string | null>(null)
const resetting = ref<string | null>(null)

const activeGroup = computed(
  () => props.groups.find((group) => group.slug === activeSlug.value) ?? null,
)

const editingItem = computed(() =>
  editingKey.value && activeGroup.value
    ? (activeGroup.value.items[editingKey.value] ?? null)
    : null,
)

const editingLabel = computed(() => {
  const name = editingItem.value?.name

  return typeof name === 'string' && name ? name : (editingKey.value ?? '')
})

const summaryFields = computed(() =>
  Object.keys(activeGroup.value?.schema ?? {})
    .filter((field) => !SUMMARY_SKIP.has(field))
    .slice(0, SUMMARY_LIMIT),
)

const reset = useApiAction(
  () => route('settings.reset', { group: activeSlug.value, key: resetting.value ?? '' }),
  { fallback: 'Reset failed' },
)

/**
 * Does this item currently have a stored override?
 */
function isOverridden(key: string): boolean {
  return activeGroup.value?.overrides.includes(key) ?? false
}

/**
 * Label for a schema field, falling back to its key.
 */
function fieldLabel(schema: Record<string, FieldMeta>, field: string): string {
  return schema[field]?.label ?? field
}

/**
 * Render a stored value for the card summary.
 */
function preview(value: unknown): string {
  if (Array.isArray(value)) {
    return value.join(', ') || '—'
  }

  return value === null || value === undefined || value === '' ? '—' : String(value)
}

/**
 * Drop an item's override and pull the refreshed groups back in.
 */
async function resetItem(key: string): Promise<void> {
  if (resetting.value !== null) {
    return
  }

  resetting.value = key
  const done = await reset.run()
  resetting.value = null

  if (done) {
    router.reload({ only: ['groups'] })
  }
}
</script>

<template>
  <div>
    <PageHeader>
      <template #title>
        <h1 class="text-2xl font-semibold text-zinc-100">Settings</h1>
      </template>
    </PageHeader>

    <div class="mb-6 flex items-center gap-1 border-b border-zinc-800">
      <button
        v-for="group in groups"
        :key="group.slug"
        type="button"
        :class="
          activeSlug === group.slug
            ? 'border-emerald-500 text-zinc-100'
            : 'border-transparent text-zinc-500 hover:text-zinc-300'
        "
        class="-mb-px cursor-pointer border-b-2 px-4 py-2 text-sm font-medium transition-colors"
        @click="activeSlug = group.slug"
      >
        {{ group.label }}
        <span v-if="group.overrides.length" class="ml-1.5 text-xs text-emerald-400">
          ({{ group.overrides.length }})
        </span>
      </button>
    </div>

    <AlertBox v-if="reset.error.value" class="mb-4">{{ reset.error.value }}</AlertBox>

    <EmptyState v-if="!activeGroup" message="No overridable settings are declared." />

    <div v-else class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
      <div
        v-for="(item, key) in activeGroup.items"
        :key="key"
        class="flex flex-col overflow-hidden rounded-xl border border-zinc-700 bg-zinc-800/50"
      >
        <div class="flex items-center justify-between gap-3 border-b border-zinc-700/60 px-5 py-4">
          <div class="flex min-w-0 items-center gap-3">
            <img
              v-if="typeof item.icon === 'string' && item.icon"
              :src="item.icon"
              :alt="String(item.name ?? key)"
              class="h-8 w-8 shrink-0 object-contain opacity-80"
            />
            <div class="min-w-0">
              <p class="truncate text-sm font-semibold text-zinc-100">
                {{ item.name ?? key }}
              </p>
              <p class="truncate font-mono text-xs text-zinc-500">{{ key }}</p>
            </div>
          </div>

          <span
            v-if="isOverridden(String(key))"
            class="shrink-0 rounded-md bg-emerald-500/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-emerald-400 ring-1 ring-inset ring-emerald-500/20"
          >
            Overridden
          </span>
        </div>

        <dl class="flex-1 space-y-1 px-5 py-3 text-xs">
          <div v-for="field in summaryFields" :key="field" class="flex gap-2">
            <dt class="w-28 shrink-0 uppercase tracking-wider text-zinc-500">
              {{ fieldLabel(activeGroup.schema, field) }}
            </dt>
            <dd class="truncate font-mono text-zinc-300">{{ preview(item[field]) }}</dd>
          </div>
        </dl>

        <div class="flex items-center justify-end gap-2 border-t border-zinc-700/60 px-5 py-3">
          <BaseButton
            v-if="isOverridden(String(key))"
            variant="ghost"
            :busy="resetting === String(key)"
            busy-label="Resetting…"
            @click="resetItem(String(key))"
          >
            Reset
          </BaseButton>
          <BaseButton variant="secondary" @click="editingKey = String(key)">Edit</BaseButton>
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
      @close="editingKey = null"
      @saved="router.reload({ only: ['groups'] })"
    />
  </div>
</template>
