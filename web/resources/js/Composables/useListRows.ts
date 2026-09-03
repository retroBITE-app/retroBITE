import { computed, ref, type ComputedRef, type Ref } from 'vue'

export type ListRows = {
  rows: Ref<string[]>
  filled: ComputedRef<string[]>
  add: () => void
  remove: (index: number) => void
  update: (index: number, value: string) => void
  reset: () => void
}

/**
 * An editable list of text rows, always keeping at least one.
 *
 * The same add/update/remove algorithm existed twice under two different naming
 * schemes, in CreateDirectory and SettingsEditModal.
 */
export function useListRows(initial: string[] = []): ListRows {
  const rows = ref<string[]>(initial.length > 0 ? [...initial] : [''])

  const filled = computed(() => rows.value.map((row) => row.trim()).filter((row) => row !== ''))

  /**
   * Append a blank row.
   */
  function add(): void {
    rows.value = [...rows.value, '']
  }

  /**
   * Remove a row, or blank it when it is the only one left.
   */
  function remove(index: number): void {
    rows.value = rows.value.length === 1 ? [''] : rows.value.filter((_, i) => i !== index)
  }

  /**
   * Replace one row, swapping the array so Vue sees the change.
   */
  function update(index: number, value: string): void {
    const next = [...rows.value]
    next[index] = value
    rows.value = next
  }

  /**
   * Replace every row, keeping at least one.
   */
  function reset(values: string[] = []): void {
    rows.value = values.length > 0 ? [...values] : ['']
  }

  return { rows, filled, add, remove, update, reset }
}
