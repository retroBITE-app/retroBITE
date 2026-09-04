/**
 * A UUID v4, falling back for contexts where crypto.randomUUID is unavailable.
 *
 * randomUUID needs a secure context, so over plain HTTP on a LAN address it is
 * undefined — which broke uploads outright, since the id names the server-side
 * staging directory and must match App\Support\PathRules::isUploadId().
 */
export function uuidV4(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }

  const bytes = randomBytes(16)

  // Set the version (4) and variant (10xx) bits the format requires.
  bytes[6] = (bytes[6] & 0x0f) | 0x40
  bytes[8] = (bytes[8] & 0x3f) | 0x80

  const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')

  return [
    hex.slice(0, 8),
    hex.slice(8, 12),
    hex.slice(12, 16),
    hex.slice(16, 20),
    hex.slice(20, 32),
  ].join('-')
}

/**
 * Random bytes from the Web Crypto API, or Math.random when it is absent.
 */
function randomBytes(length: number): Uint8Array {
  const bytes = new Uint8Array(length)

  if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
    crypto.getRandomValues(bytes)

    return bytes
  }

  for (let i = 0; i < length; i++) {
    bytes[i] = Math.floor(Math.random() * 256)
  }

  return bytes
}
