<script setup lang="ts">
import { PhX } from '@phosphor-icons/vue'

/**
 * The modal shell: backdrop, panel, optional header and footer.
 */
withDefaults(
  defineProps<{
    open: boolean
    title?: string
    subtitle?: string
    /** Blocks dismissal while a submit is in flight. */
    busy?: boolean
    size?: 'md' | 'lg' | 'xl' | '2xl'
  }>(),
  {
    busy: false,
    size: 'md',
  },
)

const emit = defineEmits<{ close: [] }>()

const SIZES = {
  md: 'max-w-md',
  lg: 'max-w-lg',
  xl: 'max-w-xl',
  '2xl': 'max-w-2xl',
} as const

/**
 * Ask the parent to close, unless a submit is in flight.
 */
function dismiss(busy: boolean): void {
  if (!busy) {
    emit('close')
  }
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-scrim/72" @click="dismiss(busy)" />

      <div
        :class="SIZES[size]"
        class="relative z-10 flex max-h-[90vh] w-full flex-col rounded-xl border border-line-input bg-surface shadow-2xl"
      >
        <div
          v-if="title || $slots.header"
          class="flex items-start justify-between gap-4 border-b border-line px-[18px] py-4"
        >
          <slot name="header">
            <div class="min-w-0">
              <h2 class="text-base font-medium text-fg-bright">{{ title }}</h2>
              <p v-if="subtitle" class="mt-0.5 truncate font-mono text-2xs text-fg-faint">
                {{ subtitle }}
              </p>
            </div>
          </slot>

          <button
            type="button"
            :disabled="busy"
            aria-label="Close"
            class="shrink-0 cursor-pointer text-fg-dim transition-colors hover:text-fg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep disabled:opacity-40"
            @click="emit('close')"
          >
            <PhX :size="16" />
          </button>
        </div>

        <div class="flex-1 overflow-y-auto px-[18px] py-5">
          <slot />
        </div>

        <div
          v-if="$slots.footer"
          class="flex items-center justify-end gap-2 border-t border-line bg-sunken px-[18px] py-3.5"
        >
          <slot name="footer" />
        </div>
      </div>
    </div>
  </Teleport>
</template>
