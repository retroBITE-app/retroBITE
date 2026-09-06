<script setup lang="ts">
/**
 * An up/down/checking indicator. The design has no success colour, so "up" is
 * the accent rather than a green.
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
      class="h-2 w-2 shrink-0 animate-pulse rounded-full bg-fg-faint"
      aria-hidden="true"
    />
    <span
      v-else-if="online"
      class="h-2 w-2 shrink-0 rounded-full bg-accent shadow-glow"
      aria-hidden="true"
    />
    <span v-else class="h-2 w-2 shrink-0 rounded-full bg-danger" aria-hidden="true" />

    <span
      class="text-sm"
      :class="online === null ? 'text-fg-faint' : online ? 'text-accent' : 'text-danger'"
    >
      {{ online === null ? 'Checking…' : online ? label + ' online' : label + ' offline' }}
    </span>
  </div>
</template>
