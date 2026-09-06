<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { PhCheck, PhTrash } from '@phosphor-icons/vue'
import { formatSize } from '@/Helpers/format'
import { route } from '@/routes'
import type { ConsoleCard } from '@/Types/api'

const props = withDefaults(
  defineProps<{
    console: ConsoleCard
    selecting?: boolean
    selected?: boolean
  }>(),
  { selecting: false, selected: false },
)

const emit = defineEmits<{ toggle: []; delete: [] }>()

/** Null with nothing to identify — a 0% bar would read as a failure. */
const identifiedPercent = computed(() =>
  props.console.game_count === 0
    ? null
    : Math.round((props.console.identified_count / props.console.game_count) * 100),
)
</script>

<template>
  <div
    class="group relative flex flex-col overflow-hidden rounded-xl bg-surface transition-colors"
    :class="
      props.selected
        ? 'border border-accent-tint/50 bg-accent-tint/8'
        : 'border border-line hover:border-line-input hover:bg-hover'
    "
  >
    <!-- The click target is stretched over the whole card rather than wrapping it,
         so the delete button can sit above it instead of inside a link. -->
    <Link
      v-if="!props.selecting"
      :href="route('console', { console: props.console.key })"
      :aria-label="props.console.name"
      class="absolute inset-0 z-10 rounded-xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
    />

    <label v-else class="absolute inset-0 z-10 cursor-pointer rounded-xl">
      <input
        type="checkbox"
        class="peer sr-only"
        :checked="props.selected"
        @change="emit('toggle')"
      />
      <span class="sr-only">Select {{ props.console.name }}</span>
      <span
        aria-hidden="true"
        class="absolute top-3 right-3 grid h-4 w-4 place-items-center rounded border transition-colors peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-accent-deep"
        :class="
          props.selected ? 'border-accent-tint bg-accent-tint/30 text-accent' : 'border-line-input'
        "
      >
        <PhCheck v-if="props.selected" :size="11" weight="bold" />
      </span>
    </label>

    <!-- Same corner as the checkbox, so switching into select mode shifts nothing. -->
    <button
      v-if="!props.selecting"
      type="button"
      :aria-label="`Delete ${props.console.name}`"
      class="absolute top-2.5 right-2.5 z-20 rounded-md p-1 text-fg-faint opacity-0 transition-colors group-hover:opacity-100 hover:bg-raised hover:text-danger focus-visible:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
      @click="emit('delete')"
    >
      <PhTrash :size="15" />
    </button>

    <!-- pr-10 is held whichever control sits in the corner, so a long name
         truncates at the same place in both modes. -->
    <div class="flex items-center gap-3.5 px-4 pt-4 pr-10 pb-3.5">
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
  </div>
</template>
