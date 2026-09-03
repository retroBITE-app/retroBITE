<script setup lang="ts">
/**
 * Title row with an optional actions cluster.
 */
</script>

<template>
  <div class="mb-6 flex items-center justify-between gap-4">
    <div class="min-w-0">
      <slot name="title" />
    </div>
    <div class="flex shrink-0 items-center gap-2">
      <slot name="actions" />
    </div>
  </div>
</template>
