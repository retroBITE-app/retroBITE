import { onUnmounted, ref, type Ref } from 'vue'

/**
 * Reactive `matchMedia`, for the one thing CSS cannot answer in script: whether
 * the sidebar is currently the off-canvas drawer or the permanent column.
 *
 * Read eagerly, so the first render already matches the stylesheet.
 */
export function useMediaQuery(query: string): Ref<boolean> {
  const list = window.matchMedia(query)
  const matches = ref(list.matches)

  /**
   * Mirror the query's current state onto the ref.
   */
  function sync(event: MediaQueryListEvent): void {
    matches.value = event.matches
  }

  list.addEventListener('change', sync)
  onUnmounted(() => list.removeEventListener('change', sync))

  return matches
}
