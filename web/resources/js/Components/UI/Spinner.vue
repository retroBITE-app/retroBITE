<script setup lang="ts">
/**
 * A loading indicator. One existed, in IdentifyModal, despite nine async sites.
 */
withDefaults(defineProps<{ label?: string }>(), { label: 'Loading…' })
</script>

<template>
  <div class="flex items-center justify-center gap-3 py-6 text-sm text-fg-muted">
    <span
      class="h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-line-bright border-t-accent"
      aria-hidden="true"
    />
    <span>{{ label }}</span>
  </div>
</template>
