<script setup lang="ts">
/**
 * An up/down/checking indicator. NetworkPanel carried this markup twice,
 * verbatim.
 */
defineProps<{
  /** null means "still checking". */
  online: boolean | null
  label: string
}>()
</script>

<template>
  <div class="flex items-center gap-2">
    <span
      v-if="online === null"
      class="h-2 w-2 shrink-0 animate-pulse rounded-full bg-zinc-500"
      aria-hidden="true"
    />
    <span
      v-else-if="online"
      class="h-2 w-2 shrink-0 rounded-full bg-emerald-500 shadow-[0_0_6px_var(--color-emerald-500)]"
      aria-hidden="true"
    />
    <span v-else class="h-2 w-2 shrink-0 rounded-full bg-red-500" aria-hidden="true" />

    <span
      class="text-xs"
      :class="online === null ? 'text-zinc-500' : online ? 'text-emerald-400' : 'text-red-400'"
    >
      {{ online === null ? 'Checking…' : online ? label + ' online' : label + ' offline' }}
    </span>
  </div>
</template>
