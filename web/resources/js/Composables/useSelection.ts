import { computed, ref, type ComputedRef, type Ref } from 'vue'

export type Selection<T> = {
  selected: Ref<Set<T>>
  count: ComputedRef<number>
  has: (value: T) => boolean
  toggle: (value: T) => void
  clear: () => void
}

/**
 * A togglable set, replacing the `next.has(x) ? next.delete(x) : next.add(x)`
 * idiom that was written twice as a bare expression statement.
 */
export function useSelection<T>(initial: T[] = []): Selection<T> {
  const selected = ref(new Set(initial)) as Ref<Set<T>>

  const count = computed(() => selected.value.size)

  /**
   * Is this value selected?
   */
  function has(value: T): boolean {
    return selected.value.has(value)
  }

  /**
   * Add or remove a value. Replaces the Set so Vue sees the change.
   */
  function toggle(value: T): void {
    const next = new Set(selected.value)

    if (next.has(value)) {
      next.delete(value)
    } else {
      next.add(value)
    }

    selected.value = next
  }

  /**
   * Deselect everything.
   */
  function clear(): void {
    selected.value = new Set()
  }

  return { selected, count, has, toggle, clear }
}
