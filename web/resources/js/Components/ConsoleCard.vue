<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

defineProps<{
  consoleKey: string
  name: string
  icon: string
  gameCount: number
  biosCount: number
}>()
</script>

<template>
  <Link
    :href="`/consoles/${consoleKey}`"
    class="group flex flex-col items-center gap-3 rounded-lg bg-zinc-800 border border-zinc-700 p-6 hover:border-emerald-500/50 hover:bg-zinc-800/80 transition-colors"
  >
    <img :src="icon" :alt="name" class="h-16 w-16 object-contain opacity-80 group-hover:opacity-100 transition-opacity" />
    <div class="text-center">
      <p class="text-sm font-medium text-zinc-200">{{ name }}</p>
      <div class="flex items-center justify-center gap-2 mt-1">
        <span class="text-xs text-zinc-500 group-hover:text-white transition-colors">{{ gameCount }} {{ gameCount === 1 ? 'game' : 'games' }}</span>
        <span v-if="biosCount > 0" class="text-xs text-zinc-500 group-hover:text-white transition-colors">{{ biosCount }} {{ biosCount === 1 ? 'BIOS' : 'Bios' }}</span>
      </div>
    </div>
  </Link>
</template>
