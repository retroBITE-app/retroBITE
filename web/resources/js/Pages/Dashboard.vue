<script setup lang="ts">
import ConsoleCard from '@/Components/ConsoleCard.vue'

defineProps<{
  consoles: Array<{
    key: string
    name: string
    icon: string
    gameCount: number
    biosCount: number
  }>
}>()
</script>

<template>
  <div>
    <h1 class="text-2xl font-semibold text-zinc-100 mb-6">Dashboard</h1>

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
</template>
