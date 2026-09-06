<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { PhPencilSimple } from '@phosphor-icons/vue'
import BaseButton from '@/Components/UI/BaseButton.vue'
import type { FieldMeta } from '@/Types/api'

/** Fields the card shows as identity rather than data: the title and the artwork. */
const IDENTITY_FIELDS = new Set(['name', 'icon', 'file_icon'])

/** Characters of the key the fallback tile shows when an item has no artwork. */
const TILE_LENGTH = 4

const props = defineProps<{
  itemKey: string
  item: Record<string, unknown>
  schema: Record<string, FieldMeta>
  overridden: boolean
  resetting: boolean
}>()

const emit = defineEmits<{ edit: []; reset: [] }>()

const title = computed(() => text(props.item.name) || props.itemKey)

const icon = computed(() => text(props.item.icon))

const iconFailed = ref(false)

watch(icon, () => (iconFailed.value = false))

/** The key, followed by whatever plain-text fields the item carries. */
const subtitle = computed(() =>
  [props.itemKey, ...fieldsOfType('text').map((field) => text(props.item[field]))]
    .filter((part) => part !== '')
    .join(' · '),
)

/** List fields with something in them — an empty one is noise on the card. */
const filledListFields = computed(() =>
  fieldsOfType('text[]').filter((field) => chips(field).length > 0),
)

/** Scalars that are neither identity nor plain text, e.g. a provider id. */
const detailFields = computed(() => [...fieldsOfType('url'), ...fieldsOfType('number')])

/**
 * Schema fields of one type, minus the identity fields.
 */
function fieldsOfType(type: FieldMeta['type']): string[] {
  return Object.entries(props.schema)
    .filter(([key, meta]) => meta.type === type && !IDENTITY_FIELDS.has(key))
    .map(([key]) => key)
}

/**
 * Label for a schema field, falling back to its key.
 */
function label(field: string): string {
  return props.schema[field]?.label ?? field
}

/**
 * The list held at a field, ignoring anything that is not one.
 */
function chips(field: string): string[] {
  const value = props.item[field]

  return Array.isArray(value) ? value.map(String) : []
}

/**
 * A field as a single line, or an em dash when it is empty.
 */
function line(field: string): string {
  return text(props.item[field]) || '—'
}

/**
 * A value as a trimmed string, empty when it is not one.
 */
function text(value: unknown): string {
  return typeof value === 'string' || typeof value === 'number' ? String(value).trim() : ''
}
</script>

<template>
  <div class="flex flex-col overflow-hidden rounded-xl border border-line bg-surface">
    <div class="flex items-center gap-3 border-b border-line px-4 py-3.5">
      <img
        v-if="icon && !iconFailed"
        :src="icon"
        alt=""
        class="h-8 w-9 shrink-0 object-contain"
        @error="iconFailed = true"
      />
      <span
        v-else
        aria-hidden="true"
        class="grid h-9 w-9 shrink-0 place-items-center rounded-lg border border-line-input bg-[linear-gradient(155deg,var(--color-raised),var(--color-surface))] font-mono text-xs text-accent-muted"
      >
        {{ itemKey.slice(0, TILE_LENGTH).toUpperCase() }}
      </span>

      <div class="min-w-0 flex-1">
        <p class="truncate text-base text-fg-bright">{{ title }}</p>
        <p class="mt-0.5 truncate font-mono text-xs text-fg-dim">{{ subtitle }}</p>
      </div>

      <button
        type="button"
        :aria-label="`Edit ${title}`"
        class="shrink-0 cursor-pointer text-fg-dim transition-colors hover:text-accent focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
        @click="emit('edit')"
      >
        <PhPencilSimple :size="15" />
      </button>
    </div>

    <div class="flex-1 px-4 pt-3.5 pb-4">
      <template v-for="field in filledListFields" :key="field">
        <p class="mt-3 kicker text-fg-faint first:mt-0">
          {{ label(field) }}
        </p>
        <div class="mt-2 flex flex-wrap gap-1.25">
          <span
            v-for="(chip, index) in chips(field)"
            :key="index"
            class="rounded border border-line-strong bg-hover px-1.5 py-0.75 font-mono text-xs text-fg-muted"
          >
            {{ chip }}
          </span>
        </div>
      </template>

      <dl v-if="detailFields.length" class="mt-3.5 space-y-1.5">
        <div v-for="field in detailFields" :key="field" class="flex items-baseline gap-3">
          <dt class="kicker text-fg-faint">
            {{ label(field) }}
          </dt>
          <dd class="min-w-0 flex-1 truncate text-right font-mono text-xs text-fg-muted">
            {{ line(field) }}
          </dd>
        </div>
      </dl>
    </div>

    <div
      v-if="overridden"
      class="flex items-center gap-2.5 border-t border-line bg-sunken px-4 py-2.5"
    >
      <span class="rounded border border-accent-tint/40 px-1.5 py-0.5 kicker text-accent">
        Overridden
      </span>
      <BaseButton
        variant="ghost"
        size="sm"
        class="ml-auto"
        :busy="resetting"
        busy-label="Resetting…"
        @click="emit('reset')"
      >
        Reset
      </BaseButton>
    </div>
  </div>
</template>
