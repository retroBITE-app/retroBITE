<script setup lang="ts">
import { ref } from 'vue'

/**
 * Copies text to the clipboard, with a fallback for the plain-HTTP LAN case —
 * navigator.clipboard needs a secure context and silently no-ops without one.
 */
const props = defineProps<{ text: string }>()

const copied = ref(false)

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
    class="shrink-0 cursor-pointer rounded px-2 py-1 text-xs text-zinc-500 transition-colors hover:bg-zinc-800 hover:text-zinc-300"
    @click="copy"
  >
    {{ copied ? 'Copied' : 'Copy' }}
  </button>
</template>
