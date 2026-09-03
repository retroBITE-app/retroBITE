<script setup lang="ts">
import ConsoleCard from '@/Components/ConsoleCard.vue'
import NetworkPanel from '@/Components/NetworkPanel.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import type { ConsoleCard as ConsoleCardData, NetworkInfo } from '@/Types/api'

const props = defineProps<{
  consoles: ConsoleCardData[]
  network: NetworkInfo
}>()
</script>

<template>
  <div class="flex flex-col gap-8">
    <section>
      <h2 class="mb-4 text-xl font-semibold text-zinc-100">Top consoles</h2>

      <EmptyState
        v-if="consoles.length === 0"
        message="No consoles installed yet."
        hint="Install one from the Consoles page to get started."
      />

      <div v-else class="grid grid-cols-2 gap-4 sm:grid-cols-3">
        <ConsoleCard v-for="entry in props.consoles" :key="entry.key" :console="entry" />
      </div>
    </section>

    <section>
      <NetworkPanel :network="props.network" />
    </section>
  </div>
</template>
