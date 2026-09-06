<script setup lang="ts">
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AlertBox from '@/Components/UI/AlertBox.vue'
import BaseButton from '@/Components/UI/BaseButton.vue'
import BaseModal from '@/Components/UI/BaseModal.vue'
import OptionList from '@/Components/UI/OptionList.vue'
import { useApiAction } from '@/Composables/useApiAction'
import { route } from '@/routes'
import type { SelectOption } from '@/Types/api'

const props = defineProps<{
  open: boolean
  consoleKey: string
  gameFileName: string
  folder: string
  folderLabel: string
  folders: SelectOption[]
}>()

const emit = defineEmits<{ close: [] }>()

const selected = ref<string | null>(null)

const move = useApiAction(
  () => route('game.move', { console: props.consoleKey, game: props.gameFileName }),
  { fallback: 'Move failed' },
)

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      selected.value = null
      move.reset()
    }
  },
)

/**
 * Move the file, then refresh the folder the page is showing.
 */
async function submit(): Promise<void> {
  if (selected.value === null) {
    return
  }

  if (await move.run({ body: { subfolder: selected.value } })) {
    emit('close')
    router.reload({ only: ['folder', 'folder_label', 'game'] })
  }
}
</script>

<template>
  <BaseModal
    :open="open"
    title="Move file"
    :subtitle="gameFileName"
    :busy="move.busy.value"
    @close="emit('close')"
  >
    <div class="space-y-4">
      <!-- Read from the prop, never looked up in `folders` — a nested current
           folder must still display correctly. -->
      <p class="text-sm text-fg-muted">
        Currently in <span class="font-mono text-fg-soft">{{ folderLabel }}</span>
      </p>

      <div>
        <p class="mb-2 kicker text-fg-faint">Move to</p>
        <OptionList
          v-model="selected"
          :options="folders"
          :disabled="move.busy.value"
          :locked-values="[folder]"
          @update:model-value="selected = $event"
        />
      </div>

      <AlertBox v-if="move.error.value">{{ move.error.value }}</AlertBox>
    </div>

    <template #footer>
      <BaseButton variant="ghost" :disabled="move.busy.value" @click="emit('close')"
        >Cancel</BaseButton
      >
      <BaseButton
        :disabled="selected === null"
        :busy="move.busy.value"
        busy-label="Moving…"
        @click="submit"
      >
        Move
      </BaseButton>
    </template>
  </BaseModal>
</template>
