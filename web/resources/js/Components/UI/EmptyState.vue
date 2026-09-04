<script setup lang="ts">
/**
 * The dashed-border "nothing here" panel.
 */
defineProps<{ message: string; hint?: string }>()
</script>

<template>
  <div class="rounded-xl border border-dashed border-line-input px-6 py-10 text-center">
    <p class="text-[13px] text-fg-soft">{{ message }}</p>
    <p v-if="hint" class="mt-1 text-xs text-fg-faint">{{ hint }}</p>
    <div v-if="$slots.default" class="mt-4 flex justify-center">
      <slot />
    </div>
  </div>
</template>
