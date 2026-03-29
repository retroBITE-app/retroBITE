<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import ConsoleCard from '@/Components/ConsoleCard.vue'
import SetupDirectories from '@/Components/SetupDirectories.vue'

defineProps<{
  consoles: Array<{
    key: string
    name: string
    icon: string
    gameCount: number
    biosCount: number
  }>
  available: Array<{
    key: string
    name: string
    uploadDirs: Array<{ value: string; label: string }>
  }>
}>()
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-semibold text-zinc-100">Consoles</h1>
      <SetupDirectories :consoles="available" title="New console" @done="router.reload()" />
    </div>

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
