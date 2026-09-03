/**
 * Read a cookie by name, or null when it is absent.
 */
function readCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`))

  return match ? decodeURIComponent(match[1]) : null
}

/**
 * Headers for hand-written fetch/XHR calls: the CSRF token CsrfMiddleware checks,
 * plus an Accept that makes an expired session answer 401 JSON instead of HTML.
 * Inertia's own requests get the token for free via axios and the XSRF-TOKEN cookie.
 */
export function apiHeaders(extra: Record<string, string> = {}): Record<string, string> {
  return {
    Accept: 'application/json',
    'X-CSRF-Token': readCookie('XSRF-TOKEN') ?? '',
    ...extra,
  }
}
