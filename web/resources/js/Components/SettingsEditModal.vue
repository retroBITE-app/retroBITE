<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { PhPlus, PhX } from '@phosphor-icons/vue'
import AlertBox from '@/Components/UI/AlertBox.vue'
import BaseButton from '@/Components/UI/BaseButton.vue'
import BaseModal from '@/Components/UI/BaseModal.vue'
import FormField from '@/Components/UI/FormField.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import { useApiAction } from '@/Composables/useApiAction'
import { route } from '@/routes'
import type { FieldMeta } from '@/Types/api'

const props = defineProps<{
  open: boolean
  title: string
  group: string
  itemKey: string
  schema: Record<string, FieldMeta>
  value: Record<string, unknown>
}>()

const emit = defineEmits<{ close: []; saved: [] }>()

const draft = ref<Record<string, unknown>>({})

const save = useApiAction(
  () => route('settings.save', { group: props.group, key: props.itemKey }),
  {
    fallback: 'Save failed',
  },
)

const fields = computed(() => Object.entries(props.schema).map(([key, meta]) => ({ key, ...meta })))

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      draft.value = seedDraft()
      save.reset()
    }
  },
)

/**
 * Build the editable copy from the current value, one entry per schema field so
 * the form shape never depends on which keys happen to be set.
 */
function seedDraft(): Record<string, unknown> {
  const seed: Record<string, unknown> = {}

  for (const [key, meta] of Object.entries(props.schema)) {
    const current = props.value?.[key]

    seed[key] =
      meta.type === 'text[]'
        ? Array.isArray(current)
          ? [...current]
          : []
        : meta.type === 'number'
          ? typeof current === 'number'
            ? current
            : ''
          : (current ?? '')
  }

  return seed
}

/**
 * The list held at a `text[]` field.
 */
function listAt(field: string): string[] {
  const current = draft.value[field]

  return Array.isArray(current) ? (current as string[]) : []
}

/**
 * Append a blank entry to a list field.
 */
function addListItem(field: string): void {
  draft.value[field] = [...listAt(field), '']
}

/**
 * Replace one entry in a list field.
 */
function updateListItem(field: string, index: number, value: string): void {
  const next = [...listAt(field)]
  next[index] = value
  draft.value[field] = next
}

/**
 * Drop one entry from a list field.
 */
function removeListItem(field: string, index: number): void {
  draft.value[field] = listAt(field).filter((_, i) => i !== index)
}

/**
 * Coerce the draft to the schema's types before sending — lists lose their blank
 * rows, and an empty number becomes null rather than 0.
 */
function payload(): Record<string, unknown> {
  const out: Record<string, unknown> = {}

  for (const [key, meta] of Object.entries(props.schema)) {
    const value = draft.value[key]

    if (meta.type === 'text[]') {
      out[key] = listAt(key)
        .map((item) => String(item).trim())
        .filter((item) => item !== '')
    } else if (meta.type === 'number') {
      out[key] = value === '' || value === null ? null : Number(value)
    } else {
      out[key] = value === null || value === undefined ? '' : String(value)
    }
  }

  return out
}

/**
 * Persist the draft.
 */
async function submit(): Promise<void> {
  if (await save.run({ json: payload() })) {
    emit('saved')
    emit('close')
  }
}
</script>

<template>
  <BaseModal
    :open="open"
    :title="`Edit ${title}`"
    :subtitle="`${group}.${itemKey}`"
    :busy="save.busy.value"
    size="xl"
    @close="emit('close')"
  >
    <div class="space-y-4">
      <FormField
        v-for="field in fields"
        :key="field.key"
        :for="`setting-${field.key}`"
        :label="field.label ?? field.key"
        :required="field.required"
        :error="save.fieldErrors.value[field.key]"
      >
        <TextInput
          v-if="field.type !== 'text[]'"
          :id="`setting-${field.key}`"
          :type="field.type === 'number' ? 'number' : 'text'"
          :model-value="String(draft[field.key] ?? '')"
          :disabled="save.busy.value"
          :invalid="Boolean(save.fieldErrors.value[field.key])"
          @update:model-value="draft[field.key] = $event"
        />

        <div v-else class="space-y-1.5">
          <div
            v-for="(item, index) in listAt(field.key)"
            :key="index"
            class="flex items-center gap-2"
          >
            <TextInput
              :model-value="item"
              mono
              :disabled="save.busy.value"
              @update:model-value="updateListItem(field.key, index, $event)"
            />
            <button
              type="button"
              title="Remove"
              aria-label="Remove entry"
              :disabled="save.busy.value"
              class="grid h-8 w-8 shrink-0 cursor-pointer place-items-center rounded-md text-fg-faint transition-colors hover:bg-hover hover:text-danger focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep disabled:opacity-40"
              @click="removeListItem(field.key, index)"
            >
              <PhX :size="14" />
            </button>
          </div>
          <button
            type="button"
            :disabled="save.busy.value"
            class="flex cursor-pointer items-center gap-1 text-sm text-accent transition-colors hover:text-accent-light focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep disabled:opacity-40"
            @click="addListItem(field.key)"
          >
            <PhPlus :size="12" />
            Add
          </button>
        </div>
      </FormField>

      <AlertBox v-if="save.error.value">{{ save.error.value }}</AlertBox>
    </div>

    <template #footer>
      <BaseButton variant="ghost" :disabled="save.busy.value" @click="emit('close')"
        >Cancel</BaseButton
      >
      <BaseButton :busy="save.busy.value" busy-label="Saving…" @click="submit">Save</BaseButton>
    </template>
  </BaseModal>
</template>
