<script setup lang="ts">
/**
 * A message panel. Eleven variations of "show a red message" existed across the
 * app, with no success or info variant at all.
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
  error: 'border-red-500/30 bg-red-500/10 text-red-400',
  warning: 'border-amber-500/30 bg-amber-500/10 text-amber-300',
  success: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300',
  info: 'border-zinc-600 bg-zinc-800/60 text-zinc-300',
} as const

const SIZES = {
  sm: 'px-3 py-2 text-xs',
  md: 'px-4 py-3 text-sm',
} as const
</script>

<template>
  <div :class="[TONES[tone], SIZES[size]]" class="rounded-md border" role="alert">
    <slot />
  </div>
</template>
