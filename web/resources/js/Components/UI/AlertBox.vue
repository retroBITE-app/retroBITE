<script setup lang="ts">
/**
 * A message panel. The design has no success colour — it uses the accent, so
 * "success" and "info" differ in weight rather than hue.
 */
withDefaults(
  defineProps<{
    tone?: 'error' | 'warning' | 'success' | 'info'
    size?: 'sm' | 'md'
  }>(),
  {
    tone: 'error',
    size: 'md',
  },
)

const TONES = {
  error: 'border-danger/35 bg-danger/10 text-danger',
  warning: 'border-warn/40 bg-warn/10 text-warn',
  success: 'border-accent-tint/40 bg-accent-tint/10 text-accent',
  info: 'border-line-strong bg-hover text-fg-soft',
} as const

const SIZES = {
  sm: 'px-3 py-2 text-xs',
  md: 'px-4 py-3 text-[13px]',
} as const
</script>

<template>
  <div :class="[TONES[tone], SIZES[size]]" class="rounded-lg border" role="alert">
    <slot />
  </div>
</template>
