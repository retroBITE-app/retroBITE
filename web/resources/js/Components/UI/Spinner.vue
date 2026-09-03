<script setup lang="ts">
/**
 * A loading indicator. One existed, in IdentifyModal, despite nine async sites.
 */
withDefaults(defineProps<{ label?: string }>(), { label: 'Loading…' })
</script>

<template>
  <div class="flex items-center justify-center gap-3 py-6 text-sm text-zinc-400">
    <span
      class="h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-zinc-600 border-t-emerald-400"
      aria-hidden="true"
    />
    <span>{{ label }}</span>
  </div>
</template>
