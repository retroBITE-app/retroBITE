<script setup lang="ts">
/**
 * Title row with an optional kicker above it and an actions cluster beside it.
 */
defineProps<{ kicker?: string }>()
</script>

<template>
  <div class="mb-6 flex items-center justify-between gap-4">
    <div class="min-w-0">
      <p v-if="kicker" class="mb-1.5 kicker text-fg-faint">
        {{ kicker }}
      </p>
      <slot name="title" />
    </div>
    <div class="flex shrink-0 items-center gap-2">
      <slot name="actions" />
    </div>
  </div>
</template>
