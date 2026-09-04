<script setup lang="ts">
import type { SelectOption } from '@/Types/api'

/**
 * A radio or checkbox list over `{value, label}` options.
 *
 * MoveModal, FileUploader and SetupDirectories each carried their own copy of
 * this markup.
 */
withDefaults(
  defineProps<{
    options: SelectOption[]
    /** A single value for radio mode, or a Set of values for checkbox mode. */
    modelValue: string | null | Set<string>
    multiple?: boolean
    disabled?: boolean
    /** Values that cannot be picked, with the reason shown beside them. */
    lockedValues?: string[]
    lockedNote?: string
    mono?: boolean
  }>(),
  {
    multiple: false,
    disabled: false,
    lockedValues: () => [],
    lockedNote: '(current)',
    mono: true,
  },
)

const emit = defineEmits<{ 'update:modelValue': [string] }>()

/**
 * Is this option currently picked?
 */
function isPicked(value: string, model: string | null | Set<string>): boolean {
  return model instanceof Set ? model.has(value) : model === value
}

/**
 * Is this option unavailable?
 */
function isLocked(value: string, locked: string[]): boolean {
  return locked.includes(value)
}
</script>

<template>
  <div class="space-y-1">
    <label
      v-for="option in options"
      :key="option.value"
      class="flex items-center gap-3 rounded-md px-3 py-2 transition-colors"
      :class="
        isLocked(option.value, lockedValues)
          ? 'cursor-not-allowed text-fg-faint opacity-60'
          : isPicked(option.value, modelValue)
            ? 'cursor-pointer bg-line-strong text-fg-bright'
            : 'cursor-pointer text-fg-cool hover:bg-hover hover:text-fg'
      "
    >
      <input
        :type="multiple ? 'checkbox' : 'radio'"
        :value="option.value"
        :checked="isPicked(option.value, modelValue)"
        :disabled="disabled || isLocked(option.value, lockedValues)"
        class="accent-accent-deep"
        @change="emit('update:modelValue', option.value)"
      />
      <span :class="mono ? 'font-mono text-sm' : 'text-sm'">{{ option.label }}</span>
      <span v-if="isLocked(option.value, lockedValues)" class="text-xs">{{ lockedNote }}</span>
    </label>
  </div>
</template>
