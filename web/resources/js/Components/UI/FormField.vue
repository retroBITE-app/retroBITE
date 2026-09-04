<script setup lang="ts">
/**
 * Label, control and error message, wired together so the label points at its
 * input. Labels are the design's mono micro-caps.
 */
defineProps<{
  label: string
  /** Must match the control's id. */
  for: string
  required?: boolean
  error?: string | null
  hint?: string
}>()
</script>

<template>
  <div>
    <label
      :for="$props.for"
      class="mb-1.5 block font-mono text-3xs tracking-[0.14em] text-fg-faint uppercase"
    >
      {{ label }}
      <span v-if="required" class="text-danger" aria-hidden="true">*</span>
    </label>

    <slot />

    <p v-if="error" class="mt-1.5 text-xs text-danger">{{ error }}</p>
    <p v-else-if="hint" class="mt-1.5 text-xs text-fg-faint">{{ hint }}</p>
  </div>
</template>
