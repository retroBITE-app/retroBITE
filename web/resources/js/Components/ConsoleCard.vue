<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { route } from '@/routes'
import type { ConsoleCard } from '@/Types/api'

const props = defineProps<{ console: ConsoleCard }>()
</script>

<template>
  <Link
    :href="route('console', { console: props.console.key })"
    class="group flex flex-col items-center gap-3 rounded-lg border border-zinc-700 bg-zinc-800 p-6 transition-colors hover:border-emerald-500/50 hover:bg-zinc-800/80"
  >
    <img
      :src="props.console.icon"
      :alt="props.console.name"
      class="h-16 w-16 object-contain opacity-80 transition-opacity group-hover:opacity-100"
    />
    <div class="text-center">
      <p class="text-sm font-medium text-zinc-200">{{ props.console.name }}</p>
      <div class="mt-1 flex items-center justify-center gap-2">
        <span class="text-xs text-zinc-500 transition-colors group-hover:text-white">
          {{ props.console.game_count }} {{ props.console.game_count === 1 ? 'game' : 'games' }}
        </span>
        <span
          v-if="props.console.bios_count > 0"
          class="text-xs text-zinc-500 transition-colors group-hover:text-white"
        >
          {{ props.console.bios_count }} BIOS
        </span>
      </div>
    </div>
  </Link>
</template>
