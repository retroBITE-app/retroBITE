<script setup lang="ts">
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import ConsoleCard from '@/Components/ConsoleCard.vue'
import PageHeader from '@/Components/PageHeader.vue'
import SetupDirectories from '@/Components/SetupDirectories.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import type { ConsoleCard as ConsoleCardData, InstallableConsole } from '@/Types/api'

const props = defineProps<{
  consoles: ConsoleCardData[]
  available: InstallableConsole[]
}>()

/** The design's kicker: what is installed and how much sits in it. */
const kicker = computed(() => {
  const games = props.consoles.reduce((total, entry) => total + entry.game_count, 0)

  return `${props.consoles.length} installed · ${games} ${games === 1 ? 'game' : 'games'}`
})
</script>

<template>
  <div>
    <PageHeader :kicker="kicker">
      <template #title>
        <h1 class="text-display font-medium tracking-display text-fg-bright">Consoles</h1>
      </template>
      <template #actions>
        <SetupDirectories :consoles="available" @done="router.reload()" />
      </template>
    </PageHeader>

    <EmptyState
      v-if="consoles.length === 0"
      message="No consoles installed yet."
      hint="Pick one with the New console button above."
    />

    <div v-else class="grid gap-3.5 sm:grid-cols-2 xl:grid-cols-3">
      <ConsoleCard v-for="entry in props.consoles" :key="entry.key" :console="entry" />
    </div>
  </div>
</template>
