<script setup lang="ts">
import AlertBox from '@/Components/UI/AlertBox.vue'
import BaseButton from '@/Components/UI/BaseButton.vue'
import BaseModal from '@/Components/UI/BaseModal.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import { useApiAction } from '@/Composables/useApiAction'
import { useModal } from '@/Composables/useModal'
import { useSelection } from '@/Composables/useSelection'
import { route } from '@/routes'

const props = defineProps<{
  consoles: Array<{ key: string; name: string }>
  title?: string
}>()

const emit = defineEmits<{ done: [] }>()

const picked = useSelection<string>()

const install = useApiAction(() => route('consoles.install'), { fallback: 'Install failed' })

const modal = useModal(install.busy, () => {
  picked.clear()
  install.reset()
})

/**
 * Install every picked console in one request. This was previously one request
 * per console, with the failures string-joined afterwards.
 */
async function submit(): Promise<void> {
  if (picked.count.value === 0) {
    return
  }

  const pairs = [...picked.selected.value].map((key): [string, string] => ['consoles[]', key])

  if (await install.run({ body: pairs })) {
    modal.open.value = false
    emit('done')
  }
}

/**
 * Failure detail keyed by console, rendered with the console's display name.
 */
function failureLines(): string[] {
  return Object.entries(install.fieldErrors.value).map(([key, reason]) => {
    const name = props.consoles.find((entry) => entry.key === key)?.name ?? key

    return `${name}: ${reason}`
  })
}
</script>

<template>
  <BaseButton variant="secondary" @click="modal.show">New console</BaseButton>

  <BaseModal
    :open="modal.open.value"
    :title="title ?? 'Install console'"
    :busy="install.busy.value"
    size="lg"
    @close="modal.hide"
  >
    <EmptyState v-if="consoles.length === 0" message="Every console is already installed." />

    <div v-else class="space-y-4">
      <p class="text-xs text-zinc-400">
        Pick one or more consoles to install. Folders get created under the games path; you can add
        subfolders later via the console page.
      </p>

      <div class="max-h-96 space-y-1 overflow-y-auto pr-1">
        <label
          v-for="entry in consoles"
          :key="entry.key"
          class="flex cursor-pointer items-center gap-3 rounded-md px-3 py-2 transition-colors"
          :class="
            picked.has(entry.key) ? 'bg-zinc-700 text-zinc-100' : 'text-zinc-400 hover:bg-zinc-800'
          "
        >
          <input
            type="checkbox"
            class="sr-only"
            :checked="picked.has(entry.key)"
            :disabled="install.busy.value"
            @change="picked.toggle(entry.key)"
          />
          <span class="text-sm">{{ entry.name }}</span>
          <span class="ml-auto font-mono text-xs text-zinc-600">{{ entry.key }}/</span>
        </label>
      </div>

      <AlertBox v-if="install.error.value" size="sm">
        <p>{{ install.error.value }}</p>
        <ul v-if="failureLines().length" class="mt-1 list-inside list-disc">
          <li v-for="line in failureLines()" :key="line">{{ line }}</li>
        </ul>
      </AlertBox>
    </div>

    <template #footer>
      <BaseButton variant="ghost" :disabled="install.busy.value" @click="modal.hide"
        >Cancel</BaseButton
      >
      <BaseButton
        v-if="consoles.length > 0"
        :disabled="picked.count.value === 0"
        :busy="install.busy.value"
        busy-label="Installing…"
        @click="submit"
      >
        Install {{ picked.count.value }} console{{ picked.count.value === 1 ? '' : 's' }}
      </BaseButton>
    </template>
  </BaseModal>
</template>
