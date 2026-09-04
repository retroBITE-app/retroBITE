<script setup lang="ts">
import { computed } from 'vue'
import { PhPlus, PhX } from '@phosphor-icons/vue'
import AlertBox from '@/Components/UI/AlertBox.vue'
import BaseButton from '@/Components/UI/BaseButton.vue'
import BaseModal from '@/Components/UI/BaseModal.vue'
import { useApiAction } from '@/Composables/useApiAction'
import { useListRows } from '@/Composables/useListRows'
import { useModal } from '@/Composables/useModal'
import { route } from '@/routes'

/** Mirrors App\Support\PathRules::isSubfolder — keep the two in step. */
const SUBFOLDER_RE = /^[a-zA-Z0-9_-]+(\/[a-zA-Z0-9_-]+)*$/

const props = defineProps<{
  consoleKey: string
  consoleName: string
  folder: string
  title?: string
}>()

const emit = defineEmits<{ done: [] }>()

const list = useListRows()

const create = useApiAction(() => route('console.mkdir', { console: props.consoleKey }), {
  fallback: 'Failed to create directory',
})

const modal = useModal(create.busy, () => {
  list.reset()
  create.reset()
})

const allValid = computed(
  () => list.filled.value.length > 0 && list.filled.value.every((row) => SUBFOLDER_RE.test(row)),
)

const anyInvalid = computed(() => list.rows.value.some(isInvalid))

/**
 * Is this row non-empty but not a legal folder name?
 */
function isInvalid(value: string): boolean {
  const trimmed = value.trim()

  return trimmed !== '' && !SUBFOLDER_RE.test(trimmed)
}

/**
 * Create every filled row as a subfolder.
 */
async function submit(): Promise<void> {
  if (!allValid.value) {
    return
  }

  const pairs = list.filled.value.map((row): [string, string] => ['subfolders[]', row])

  if (await create.run({ body: pairs })) {
    modal.open.value = false
    emit('done')
  }
}
</script>

<template>
  <BaseButton variant="secondary" @click="modal.show">New directory</BaseButton>

  <BaseModal
    :open="modal.open.value"
    :title="`${title ?? 'New directory'} — ${consoleName}`"
    :busy="create.busy.value"
    @close="modal.hide"
  >
    <div class="space-y-4">
      <p class="text-xs text-fg-dim">
        Create one or more folders under <span class="font-mono text-fg-soft">{{ folder }}/</span>.
        Use <span class="font-mono">nested/path</span> for sub-subfolders.
      </p>

      <div class="space-y-2">
        <div v-for="(row, index) in list.rows.value" :key="index" class="flex items-center gap-2">
          <div
            class="flex flex-1 items-center overflow-hidden rounded-md border"
            :class="isInvalid(row) ? 'border-danger/70' : 'border-line-strong'"
          >
            <span
              class="shrink-0 border-r border-line-strong bg-hover px-3 py-2 font-mono text-[13px] text-fg-dim"
            >
              {{ folder }}/
            </span>
            <input
              :value="row"
              type="text"
              placeholder="folder-name"
              :disabled="create.busy.value"
              class="flex-1 bg-sunken px-3 py-2 font-mono text-[13px] text-fg placeholder-fg-faint outline-none disabled:opacity-50"
              @input="list.update(index, ($event.target as HTMLInputElement).value)"
            />
          </div>
          <button
            type="button"
            title="Remove"
            aria-label="Remove folder"
            :disabled="create.busy.value"
            class="grid h-8 w-8 shrink-0 cursor-pointer place-items-center rounded-md text-fg-faint transition-colors hover:bg-hover hover:text-danger focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep disabled:opacity-40"
            @click="list.remove(index)"
          >
            <PhX :size="14" />
          </button>
        </div>
      </div>

      <button
        type="button"
        :disabled="create.busy.value"
        class="flex cursor-pointer items-center gap-1 text-xs text-accent transition-colors hover:text-accent-light focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep disabled:opacity-40"
        @click="list.add"
      >
        <PhPlus :size="12" />
        Add folder
      </button>

      <AlertBox v-if="anyInvalid" size="sm">
        Only letters, numbers, hyphens, underscores, and slashes (for nesting) allowed.
      </AlertBox>
      <AlertBox v-if="create.error.value" size="sm">{{ create.error.value }}</AlertBox>
    </div>

    <template #footer>
      <BaseButton variant="ghost" :disabled="create.busy.value" @click="modal.hide"
        >Cancel</BaseButton
      >
      <BaseButton
        :disabled="!allValid"
        :busy="create.busy.value"
        busy-label="Creating…"
        @click="submit"
      >
        {{
          list.filled.value.length > 1
            ? `Create ${list.filled.value.length} folders`
            : 'Create folder'
        }}
      </BaseButton>
    </template>
  </BaseModal>
</template>
