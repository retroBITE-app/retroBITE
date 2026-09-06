<script setup lang="ts">
/**
 * An on/off switch. A real button rather than a styled checkbox, so Space and
 * Enter both work and the label can sit outside it.
 */
withDefaults(
  defineProps<{
    modelValue: boolean
    label: string
    disabled?: boolean
  }>(),
  { disabled: false },
)

const emit = defineEmits<{ 'update:modelValue': [boolean] }>()
</script>

<template>
  <button
    type="button"
    role="switch"
    :aria-checked="modelValue"
    :aria-label="label"
    :disabled="disabled"
    class="relative h-4.75 w-8.5 shrink-0 cursor-pointer rounded-full border transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep disabled:cursor-not-allowed disabled:opacity-50"
    :class="modelValue ? 'border-accent-tint/60 bg-accent-tint/35' : 'border-line-input bg-raised'"
    @click="emit('update:modelValue', !modelValue)"
  >
    <span
      aria-hidden="true"
      class="absolute top-0.5 block h-3.25 w-3.25 rounded-full transition-[left,background-color] duration-150 motion-reduce:transition-none"
      :class="modelValue ? 'left-4.5 bg-accent' : 'left-0.5 bg-fg-faint'"
    />
  </button>
</template>
