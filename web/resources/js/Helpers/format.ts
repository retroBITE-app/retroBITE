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

/** Seconds in each step, largest first, for formatRelative. */
const INTERVALS = [
  ['y', 31_536_000],
  ['mo', 2_592_000],
  ['w', 604_800],
  ['d', 86_400],
  ['h', 3_600],
  ['m', 60],
] as const

/**
 * A unix timestamp as a short age, e.g. "3d ago". Anything under a minute reads
 * as "just now", since the scan that wrote it has only just finished.
 */
export function formatRelative(timestamp: number | null | undefined): string {
  if (!timestamp) {
    return '—'
  }

  const seconds = Math.max(0, Math.floor(Date.now() / 1000) - timestamp)

  for (const [unit, size] of INTERVALS) {
    if (seconds >= size) {
      return `${Math.floor(seconds / size)}${unit} ago`
    }
  }

  return 'just now'
}
