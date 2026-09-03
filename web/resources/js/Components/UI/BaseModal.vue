<script setup lang="ts">
/**
 * The modal shell: backdrop, panel, optional header and footer.
 *
 * Seven components each carried their own copy of this markup, with the
 * dismiss guard written four different ways.
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
      <div class="absolute inset-0 bg-black/60" @click="dismiss(busy)" />

      <div
        :class="SIZES[size]"
        class="relative z-10 flex max-h-[90vh] w-full flex-col rounded-xl bg-zinc-900 border border-zinc-700 shadow-2xl"
      >
        <div
          v-if="title || $slots.header"
          class="flex items-start justify-between gap-4 px-6 py-4 border-b border-zinc-800"
        >
          <slot name="header">
            <div class="min-w-0">
              <h2 class="text-base font-semibold text-zinc-100">{{ title }}</h2>
              <p v-if="subtitle" class="text-xs font-mono text-zinc-500 truncate mt-0.5">
                {{ subtitle }}
              </p>
            </div>
          </slot>

          <button
            type="button"
            :disabled="busy"
            aria-label="Close"
            class="shrink-0 cursor-pointer text-zinc-500 hover:text-zinc-300 transition-colors disabled:opacity-40"
            @click="emit('close')"
          >
            ✕
          </button>
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-5">
          <slot />
        </div>

        <div
          v-if="$slots.footer"
          class="flex items-center justify-end gap-2 px-6 py-4 border-t border-zinc-800"
        >
          <slot name="footer" />
        </div>
      </div>
    </div>
  </Teleport>
</template>
