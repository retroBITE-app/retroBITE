<script setup lang="ts">
/**
 * The dashed-border "nothing here" panel, previously duplicated three times.
 */
defineProps<{ message: string; hint?: string }>()
</script>

<template>
  <div class="rounded-xl border border-dashed border-zinc-700 px-6 py-10 text-center">
    <p class="text-sm text-zinc-400">{{ message }}</p>
    <p v-if="hint" class="mt-1 text-xs text-zinc-500">{{ hint }}</p>
    <div v-if="$slots.default" class="mt-4 flex justify-center">
      <slot />
    </div>
  </div>
</template>
