<script setup lang="ts">
import { PhCheck, PhPlus } from '@phosphor-icons/vue'
import AlertBox from '@/Components/UI/AlertBox.vue'
import BaseButton from '@/Components/UI/BaseButton.vue'
import BaseModal from '@/Components/UI/BaseModal.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import { useApiAction } from '@/Composables/useApiAction'
import { useModal } from '@/Composables/useModal'
import { useSelection } from '@/Composables/useSelection'
import { route } from '@/routes'
import type { InstallableConsole } from '@/Types/api'

const props = defineProps<{ consoles: InstallableConsole[] }>()

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
  <BaseButton class="flex items-center gap-1.5" @click="modal.show">
    <PhPlus :size="14" />
    New console
  </BaseButton>

  <BaseModal
    :open="modal.open.value"
    title="New console"
    :busy="install.busy.value"
    size="lg"
    @close="modal.hide"
  >
    <EmptyState v-if="consoles.length === 0" message="Every console is already installed." />

    <div v-else class="space-y-4">
      <p class="text-xs leading-relaxed text-fg-dim">
        Folders are created under the games path. Subfolders can be added later from the console
        page.
      </p>

      <div class="max-h-[300px] space-y-1 overflow-y-auto pr-1">
        <label
          v-for="entry in consoles"
          :key="entry.key"
          class="flex cursor-pointer items-center gap-2.5 rounded-lg border px-2.5 py-2.5 transition-colors"
          :class="
            picked.has(entry.key)
              ? 'border-accent-tint/50 bg-accent-tint/8 text-fg'
              : 'border-line-strong text-fg-cool hover:border-line-bright hover:text-fg'
          "
        >
          <input
            type="checkbox"
            class="peer sr-only"
            :checked="picked.has(entry.key)"
            :disabled="install.busy.value"
            @change="picked.toggle(entry.key)"
          />
          <span
            aria-hidden="true"
            class="grid h-4 w-4 shrink-0 place-items-center rounded border transition-colors peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-accent-deep"
            :class="
              picked.has(entry.key)
                ? 'border-accent-tint bg-accent-tint/30 text-accent'
                : 'border-line-input'
            "
          >
            <PhCheck v-if="picked.has(entry.key)" :size="11" weight="bold" />
          </span>
          <span class="flex-1 text-[13.5px]">{{ entry.name }}</span>
          <span class="font-mono text-2xs text-fg-faint">{{ entry.key }}/</span>
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
      <p v-if="consoles.length > 0" class="mr-auto font-mono text-2xs text-fg-faint">
        {{ picked.count.value }} selected
      </p>
      <BaseButton variant="ghost" :disabled="install.busy.value" @click="modal.hide">
        Cancel
      </BaseButton>
      <BaseButton
        v-if="consoles.length > 0"
        :disabled="picked.count.value === 0"
        :busy="install.busy.value"
        busy-label="Installing…"
        @click="submit"
      >
        Install
      </BaseButton>
    </template>
  </BaseModal>
</template>
