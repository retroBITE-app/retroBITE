<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { formatSize } from '@/Helpers/format'
import { route } from '@/routes'
import type { ConsoleCard } from '@/Types/api'

const props = defineProps<{ console: ConsoleCard }>()

/** Null with nothing to identify — a 0% bar would read as a failure. */
const identifiedPercent = computed(() =>
  props.console.game_count === 0
    ? null
    : Math.round((props.console.identified_count / props.console.game_count) * 100),
)
</script>

<template>
  <Link
    :href="route('console', { console: props.console.key })"
    class="flex flex-col overflow-hidden rounded-xl border border-line bg-surface transition-colors hover:border-line-input hover:bg-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
  >
    <div class="flex items-center gap-3.5 px-4 pt-4 pb-3.5">
      <img
        :src="props.console.icon"
        :alt="props.console.name"
        class="h-11 w-11 shrink-0 object-contain"
      />
      <div class="min-w-0">
        <p class="truncate text-base text-fg-bright">{{ props.console.name }}</p>
        <p class="mt-0.5 truncate font-mono text-xs text-fg-dim">{{ props.console.path }}</p>
      </div>
    </div>

    <div class="px-4 pb-3.5">
      <div class="flex justify-between kicker text-fg-faint">
        <span>Identified</span>
        <span>{{ identifiedPercent === null ? '—' : `${identifiedPercent}%` }}</span>
      </div>
      <div class="mt-1.5 h-1 overflow-hidden rounded-sm bg-raised">
        <!-- Amber only at the finish line, so a complete console stands out. -->
        <div
          class="h-full rounded-sm transition-[width] duration-300"
          :class="identifiedPercent === 100 ? 'bg-accent-deep' : 'bg-accent-muted'"
          :style="{ width: `${identifiedPercent ?? 0}%` }"
        />
      </div>
    </div>

    <div
      class="mt-auto flex items-center gap-3.5 border-t border-line bg-sunken px-4 py-2.5 font-mono text-xs text-fg-dim"
    >
      <span
        >{{ props.console.game_count }}
        {{ props.console.game_count === 1 ? 'game' : 'games' }}</span
      >
      <span v-if="props.console.bios_count > 0">{{ props.console.bios_count }} BIOS</span>
      <span class="ml-auto text-fg-faint">{{ formatSize(props.console.bytes) }}</span>
    </div>
  </Link>
</template>
