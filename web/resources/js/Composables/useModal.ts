import { ref, type Ref } from 'vue'

export type Modal = {
  open: Ref<boolean>
  show: () => void
  hide: () => void
}

/**
 * Open/closed state for a modal that owns its own trigger, refusing to close
 * while `busy` is true so a submit in flight cannot be dismissed.
 */
export function useModal(busy?: Ref<boolean>, onShow?: () => void): Modal {
  const open = ref(false)

  /**
   * Open the modal, running the caller's reset hook first.
   */
  function show(): void {
    onShow?.()
    open.value = true
  }

  /**
   * Close the modal, unless a submit is in flight.
   */
  function hide(): void {
    if (busy?.value) {
      return
    }

    open.value = false
  }

  return { open, show, hide }
}
