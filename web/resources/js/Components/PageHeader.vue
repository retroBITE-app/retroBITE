<script setup lang="ts">
/**
 * Title row with an optional kicker above it and an actions cluster beside it.
 */
defineProps<{ kicker?: string }>()
</script>

<template>
  <div class="mb-6 flex items-center justify-between gap-4">
    <div class="min-w-0">
      <p v-if="kicker" class="mb-1.5 font-mono text-3xs tracking-[0.2em] text-fg-faint uppercase">
        {{ kicker }}
      </p>
      <slot name="title" />
    </div>
    <div class="flex shrink-0 items-center gap-2">
      <slot name="actions" />
    </div>
  </div>
</template>
