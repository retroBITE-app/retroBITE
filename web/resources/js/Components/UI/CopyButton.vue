<script setup lang="ts">
import { ref } from 'vue'

/**
 * Copies text to the clipboard, with a fallback for the plain-HTTP LAN case —
 * navigator.clipboard needs a secure context and silently no-ops without one.
 */
const props = withDefaults(
  defineProps<{
    text: string
    label?: string
    /** `outline` is the design's secondary action, `menu` a dropdown row, `plain` a list row. */
    variant?: 'plain' | 'outline' | 'menu'
  }>(),
  { label: 'Copy', variant: 'plain' },
)

const copied = ref(false)

const VARIANTS = {
  plain: 'rounded px-2 py-1 text-xs text-fg-faint hover:bg-hover hover:text-fg-soft',
  outline:
    'rounded-lg border border-line-input px-3.5 py-2 text-[13px] text-fg-soft hover:border-line-bright hover:text-fg',
  menu: 'flex w-full items-center gap-2.5 rounded-[7px] px-2.5 py-2 text-[13px] text-fg-soft hover:bg-raised',
} as const

/**
 * Copy, then flash confirmation for a moment.
 */
async function copy(): Promise<void> {
  if (!(await writeToClipboard(props.text))) {
    return
  }

  copied.value = true
  window.setTimeout(() => (copied.value = false), 1500)
}

/**
 * Write to the clipboard, falling back to a hidden textarea and execCommand
 * when the Clipboard API is unavailable.
 */
async function writeToClipboard(text: string): Promise<boolean> {
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text)

      return true
    }
  } catch {
    // Fall through to the legacy path.
  }

  const field = document.createElement('textarea')
  field.value = text
  field.setAttribute('readonly', '')
  field.style.position = 'fixed'
  field.style.opacity = '0'
  document.body.appendChild(field)
  field.select()

  const ok = document.execCommand('copy')
  document.body.removeChild(field)

  return ok
}
</script>

<template>
  <button
    type="button"
    :class="VARIANTS[props.variant]"
    class="shrink-0 cursor-pointer transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
    @click="copy"
  >
    <slot name="icon" />
    {{ copied ? 'Copied' : props.label }}
  </button>
</template>
