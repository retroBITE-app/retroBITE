<script setup lang="ts">
/**
 * Label, control and error message, wired together so the label actually points
 * at its input — only the login form did that before.
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
    <label :for="$props.for" class="mb-1 block text-xs font-medium text-zinc-400">
      {{ label }}
      <span v-if="required" class="text-red-400" aria-hidden="true">*</span>
    </label>

    <slot />

    <p v-if="error" class="mt-1 text-xs text-red-400">{{ error }}</p>
    <p v-else-if="hint" class="mt-1 text-xs text-zinc-500">{{ hint }}</p>
  </div>
</template>
