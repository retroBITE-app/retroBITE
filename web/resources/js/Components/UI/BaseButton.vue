<script setup lang="ts">
/**
 * The one button. The design fills nothing solid — actions are outlined or bare,
 * with the amber ramp carrying the primary weight.
 */
withDefaults(
  defineProps<{
    variant?: 'primary' | 'secondary' | 'ghost' | 'danger'
    size?: 'sm' | 'md'
    busy?: boolean
    busyLabel?: string
    disabled?: boolean
    type?: 'button' | 'submit'
  }>(),
  {
    variant: 'primary',
    size: 'md',
    busy: false,
    disabled: false,
    type: 'button',
  },
)

const VARIANTS = {
  primary: 'border border-accent-tint/55 text-accent hover:bg-accent-tint/12',
  secondary: 'border border-line-strong text-fg-soft hover:border-line-bright hover:text-fg',
  danger: 'border border-danger/50 text-danger hover:bg-danger/10',
  ghost: 'border border-transparent text-fg-cool hover:text-fg',
} as const

const SIZES = {
  sm: 'px-2 py-1 text-sm',
  md: 'px-3.5 py-2 text-sm',
} as const
</script>

<template>
  <button
    :type="type"
    :disabled="disabled || busy"
    :class="[VARIANTS[variant], SIZES[size]]"
    class="cursor-pointer rounded-lg transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep disabled:cursor-not-allowed disabled:opacity-50"
  >
    <template v-if="busy && busyLabel">{{ busyLabel }}</template>
    <slot v-else />
  </button>
</template>
