<script setup lang="ts">
/**
 * The one button. Replaces six copies of the primary class string, six of the
 * secondary, seven of the ghost, and nine hand-rolled `busy ? 'Verb…' : 'Verb'`
 * labels.
 */
withDefaults(
  defineProps<{
    variant?: 'primary' | 'secondary' | 'ghost' | 'danger'
    busy?: boolean
    busyLabel?: string
    disabled?: boolean
    type?: 'button' | 'submit'
  }>(),
  {
    variant: 'primary',
    busy: false,
    disabled: false,
    type: 'button',
  },
)

const VARIANTS = {
  primary: 'bg-emerald-600 hover:bg-emerald-500 text-white font-medium',
  secondary: 'bg-zinc-700 hover:bg-zinc-600 text-white font-medium',
  danger: 'bg-red-600 hover:bg-red-500 text-white font-medium',
  ghost: 'text-zinc-400 hover:text-zinc-200',
} as const
</script>

<template>
  <button
    :type="type"
    :disabled="disabled || busy"
    :class="VARIANTS[variant]"
    class="px-4 py-2 rounded-md text-sm cursor-pointer transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
  >
    <template v-if="busy && busyLabel">{{ busyLabel }}</template>
    <slot v-else />
  </button>
</template>
