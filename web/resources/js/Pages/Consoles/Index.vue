<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import ConsoleCard from '@/Components/ConsoleCard.vue'
import PageHeader from '@/Components/PageHeader.vue'
import SetupDirectories from '@/Components/SetupDirectories.vue'
import AlertBox from '@/Components/UI/AlertBox.vue'
import BaseButton from '@/Components/UI/BaseButton.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import { useApiAction } from '@/Composables/useApiAction'
import { useSelection } from '@/Composables/useSelection'
import { formatSize } from '@/Helpers/format'
import { route } from '@/routes'
import type { ConsoleCard as ConsoleCardData, InstallableConsole } from '@/Types/api'

const props = defineProps<{
  consoles: ConsoleCardData[]
  available: InstallableConsole[]
}>()

const picked = useSelection<string>()

const selecting = ref(false)
const deleteOpen = ref(false)

/** The keys the dialog is about, so one card's trash and a whole selection share it. */
const pending = ref<string[]>([])

const destroy = useApiAction<{ games_removed: number }>(() => route('consoles.destroy'), {
  method: 'DELETE',
  fallback: 'Delete failed',
})

/** The design's kicker: what is installed and how much sits in it. */
const kicker = computed(() => {
  const games = props.consoles.reduce((total, entry) => total + entry.game_count, 0)

  return `${props.consoles.length} installed · ${games} ${games === 1 ? 'game' : 'games'}`
})

const pendingConsoles = computed(() =>
  props.consoles.filter((entry) => pending.value.includes(entry.key)),
)

const pendingGames = computed(() =>
  pendingConsoles.value.reduce((total, entry) => total + entry.game_count, 0),
)

const pendingBytes = computed(() =>
  pendingConsoles.value.reduce((total, entry) => total + entry.bytes, 0),
)

/** Names one console but counts several — a list of twelve names is not a title. */
const deleteTitle = computed(() =>
  pendingConsoles.value.length === 1
    ? `Delete ${pendingConsoles.value[0].name}?`
    : `Delete ${pendingConsoles.value.length} consoles?`,
)

/**
 * Open the confirmation for a set of consoles. One entry point for the card's
 * trash and the bulk bar, so there is a single confirmation to keep honest.
 */
function openDelete(keys: string[]): void {
  if (keys.length === 0) {
    return
  }

  destroy.reset()
  pending.value = keys
  deleteOpen.value = true
}

/** Leave select mode, dropping whatever was picked. */
function exitSelect(): void {
  picked.clear()
  selecting.value = false
}

/**
 * Delete every pending console in one request, then reload so the available list
 * and the sidebar agree with disk again. A failure keeps the dialog open, where
 * the per-console reason is rendered.
 */
async function confirmDelete(): Promise<void> {
  const pairs = pending.value.map((key): [string, string] => ['consoles[]', key])

  if (await destroy.run({ body: pairs })) {
    deleteOpen.value = false
    pending.value = []
    exitSelect()
    router.reload()
  }
}

/** Failure detail keyed by console, rendered with the console's display name. */
function failureLines(): string[] {
  return Object.entries(destroy.fieldErrors.value).map(([key, reason]) => {
    const name = props.consoles.find((entry) => entry.key === key)?.name ?? key

    return `${name}: ${reason}`
  })
}
</script>

<template>
  <div>
    <PageHeader :kicker="kicker">
      <template #title>
        <h1 class="text-display font-medium tracking-display text-fg-bright">Consoles</h1>
      </template>
      <template #actions>
        <BaseButton
          v-if="props.consoles.length > 0"
          variant="ghost"
          @click="selecting ? exitSelect() : (selecting = true)"
        >
          {{ selecting ? 'Done' : 'Select' }}
        </BaseButton>
        <SetupDirectories :consoles="available" @done="router.reload()" />
      </template>
    </PageHeader>

    <div
      v-if="selecting"
      class="mb-3.5 flex items-center gap-2 rounded-xl border border-line-strong bg-sunken px-4 py-2.5"
    >
      <p class="kicker text-fg-faint">{{ picked.count.value }} selected</p>
      <BaseButton variant="ghost" size="sm" class="ml-auto" @click="exitSelect">Cancel</BaseButton>
      <BaseButton
        variant="danger"
        size="sm"
        :disabled="picked.count.value === 0"
        @click="openDelete([...picked.selected.value])"
      >
        Delete
      </BaseButton>
    </div>

    <EmptyState
      v-if="consoles.length === 0"
      message="No consoles installed yet."
      hint="Pick one with the New console button above."
    />

    <div v-else class="grid gap-3.5 sm:grid-cols-2 xl:grid-cols-3">
      <ConsoleCard
        v-for="entry in props.consoles"
        :key="entry.key"
        :console="entry"
        :selecting="selecting"
        :selected="picked.has(entry.key)"
        @toggle="picked.toggle(entry.key)"
        @delete="openDelete([entry.key])"
      />
    </div>

    <ConfirmDialog
      :open="deleteOpen"
      :title="deleteTitle"
      variant="danger"
      confirm-label="Delete forever"
      :busy="destroy.busy.value"
      @cancel="deleteOpen = false"
      @confirm="confirmDelete"
    >
      <div class="space-y-3">
        <p class="text-sm leading-relaxed text-fg-soft">
          The folder
          {{ pendingConsoles.length === 1 ? 'is' : 'and everything under each one are' }}
          removed from the games path, and the library entries go with
          {{ pendingConsoles.length === 1 ? 'it' : 'them' }}.
        </p>

        <ul class="divide-y divide-line rounded-lg border border-line-strong">
          <li
            v-for="entry in pendingConsoles"
            :key="entry.key"
            class="flex items-center gap-3 px-3 py-2"
          >
            <img :src="entry.icon" :alt="entry.name" class="h-6 w-6 shrink-0 object-contain" />
            <span class="min-w-0 flex-1 truncate text-sm text-fg">{{ entry.name }}</span>
            <span class="shrink-0 font-mono text-xs text-fg-dim">
              {{ entry.game_count }} {{ entry.game_count === 1 ? 'game' : 'games' }}
            </span>
            <span class="w-18 shrink-0 text-right font-mono text-xs text-fg-faint">
              {{ formatSize(entry.bytes) }}
            </span>
          </li>
        </ul>

        <AlertBox tone="error" size="sm">
          {{ pendingGames }} {{ pendingGames === 1 ? 'game' : 'games' }} and
          {{ formatSize(pendingBytes) }} are deleted from disk permanently. This cannot be undone.
        </AlertBox>

        <AlertBox v-if="destroy.error.value" tone="error" size="sm">
          <p>{{ destroy.error.value }}</p>
          <ul v-if="failureLines().length" class="mt-1 list-inside list-disc">
            <li v-for="line in failureLines()" :key="line">{{ line }}</li>
          </ul>
        </AlertBox>
      </div>
    </ConfirmDialog>
  </div>
</template>
