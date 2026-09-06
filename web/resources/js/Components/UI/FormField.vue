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
    <label :for="$props.for" class="mb-1.5 block kicker text-fg-faint">
      {{ label }}
      <span v-if="required" class="text-danger" aria-hidden="true">*</span>
    </label>

    <slot />

    <p v-if="error" class="mt-1.5 text-sm text-danger">{{ error }}</p>
    <p v-else-if="hint" class="mt-1.5 text-sm text-fg-faint">{{ hint }}</p>
  </div>
</template>
