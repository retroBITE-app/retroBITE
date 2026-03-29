<script setup lang="ts">
import ConsoleCard from '@/Components/ConsoleCard.vue'
import NetworkPanel from '@/Components/NetworkPanel.vue'
import AchievementsPanel from '@/Components/AchievementsPanel.vue'

const props = defineProps<{
  consoles: Array<{
    key: string
    name: string
    icon: string
    gameCount: number
    biosCount: number
  }>
  network: { hostIp: string; username: string }
}>()
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="flex flex-col">
      <h2 class="text-xl font-semibold text-zinc-100 mb-4">Top Consoles</h2>
      <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
        <ConsoleCard
          v-for="c in consoles"
          :key="c.key"
          :console-key="c.key"
          :name="c.name"
          :icon="c.icon"
          :game-count="c.gameCount"
          :bios-count="c.biosCount"
        />
      </div>
    </div>

    <div class="flex flex-col">
      <h2 class="text-xl font-semibold text-zinc-100 mb-4">Latest Achievements</h2>
      <AchievementsPanel />
    </div>

    <div class="flex flex-col">
      <h2 class="text-xl font-semibold text-zinc-100 mb-4">Network</h2>
      <NetworkPanel :network="props.network" />
    </div>

  </div>
</template>
