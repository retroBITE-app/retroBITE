/** Unit steps for formatSize, largest first. */
const UNITS = ['B', 'KB', 'MB', 'GB', 'TB'] as const

/**
 * Human-readable byte count, e.g. "4.7 GB". Renders an unknown size as a dash.
 */
export function formatSize(bytes: number | null | undefined): string {
  if (bytes === null || bytes === undefined || Number.isNaN(bytes)) {
    return '—'
  }

  let value = bytes
  let unit = 0

  while (value >= 1024 && unit < UNITS.length - 1) {
    value /= 1024
    unit++
  }

  return `${unit === 0 ? value : value.toFixed(1)} ${UNITS[unit]}`
}

/**
 * A unix timestamp as a local date, or a dash when absent.
 */
export function formatDate(timestamp: number | null | undefined): string {
  if (!timestamp) {
    return '—'
  }

  return new Date(timestamp * 1000).toLocaleDateString()
}
