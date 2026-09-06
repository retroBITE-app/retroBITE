<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import SettingsAbout from '@/Components/SettingsAbout.vue'
import SettingsEditModal from '@/Components/SettingsEditModal.vue'
import SettingsInterface from '@/Components/SettingsInterface.vue'
import SettingsItemCard from '@/Components/SettingsItemCard.vue'
import AlertBox from '@/Components/UI/AlertBox.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import { useApiAction } from '@/Composables/useApiAction'
import { route } from '@/routes'
import type { AboutData, SettingsGroup } from '@/Types/api'

/** About is content rather than an overridable group, so it gets its own tab slug. */
const ABOUT_TAB = 'about'

/** Groups that render as their own panel instead of the item card grid. */
const PANEL_GROUPS = new Set(['interface'])

const props = defineProps<{ groups: SettingsGroup[]; about: AboutData }>()

const activeSlug = ref(ABOUT_TAB)
const editingKey = ref<string | null>(null)
const resetting = ref<string | null>(null)

const tabs = computed(() => [
  { slug: ABOUT_TAB, label: 'About', count: '' },
  ...props.groups.map((group) => ({
    slug: group.slug,
    label: group.label,
    // A panel group holds one item, so its count would read "1" and mean nothing.
    count: PANEL_GROUPS.has(group.slug) ? '' : String(Object.keys(group.items).length),
  })),
])

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

const reset = useApiAction(
  () => route('settings.reset', { group: activeSlug.value, key: resetting.value ?? '' }),
  { fallback: 'Reset failed' },
)

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
    <PageHeader kicker="Global">
      <template #title>
        <h1 class="text-display font-medium tracking-display text-fg-bright">Settings</h1>
      </template>
    </PageHeader>

    <div class="mb-6 flex gap-5.5 overflow-x-auto border-b border-raised">
      <button
        v-for="tab in tabs"
        :key="tab.slug"
        type="button"
        :class="
          activeSlug === tab.slug
            ? 'text-fg-bright shadow-underline'
            : 'text-fg-muted hover:text-fg-soft'
        "
        class="shrink-0 cursor-pointer pb-2.75 text-sm whitespace-nowrap transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
        @click="activeSlug = tab.slug"
      >
        {{ tab.label }}
        <span v-if="tab.count" class="ml-1.5 font-mono text-xs opacity-75">{{ tab.count }}</span>
      </button>
    </div>

    <AlertBox v-if="reset.error.value" class="mb-4">{{ reset.error.value }}</AlertBox>

    <SettingsAbout v-if="activeSlug === ABOUT_TAB" :about="props.about" />

    <EmptyState v-else-if="!activeGroup" message="No overridable settings are declared." />

    <SettingsInterface v-else-if="PANEL_GROUPS.has(activeGroup.slug)" :group="activeGroup" />

    <div v-else class="grid gap-3.5 md:grid-cols-2 xl:grid-cols-3">
      <SettingsItemCard
        v-for="(item, key) in activeGroup.items"
        :key="key"
        :item-key="String(key)"
        :item="item"
        :schema="activeGroup.schema"
        :overridden="activeGroup.overrides.includes(String(key))"
        :resetting="resetting === String(key)"
        @edit="editingKey = String(key)"
        @reset="resetItem(String(key))"
      />
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
