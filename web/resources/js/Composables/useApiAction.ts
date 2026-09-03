import { ref, type Ref } from 'vue'
import { apiHeaders } from '@/Helpers/http'
import type { ApiError } from '@/Types/api'

type Body = Record<string, string | number | boolean | null | undefined> | string[][] | FormData

export type ApiAction<T> = {
  /** Is a call in flight? */
  busy: Ref<boolean>
  /** Message from the last failure, cleared when a new call starts. */
  error: Ref<string | null>
  /** Per-field messages when the failure carried them. */
  fieldErrors: Ref<Record<string, string>>
  /** Run the action. Resolves with the response body, or null on failure. */
  run: (options?: RequestOptions) => Promise<T | null>
  /** Forget the last failure. */
  reset: () => void
}

type RequestOptions = {
  method?: 'GET' | 'POST' | 'DELETE'
  /** Form-encoded payload: an object, or `[name, value]` pairs for repeated keys. */
  body?: Body
  /** JSON payload. Mutually exclusive with `body`. */
  json?: unknown
  /** Fallback message when the failure carries no readable one. */
  fallback?: string
}

/**
 * One request with its own busy and error state.
 *
 * Replaces the busy/try/catch/finally block that was copy-pasted into nine
 * components, each naming the same boolean differently.
 */
export function useApiAction<T = unknown>(
  url: () => string,
  defaults: RequestOptions = {},
): ApiAction<T> {
  const busy = ref(false)
  const error = ref<string | null>(null)
  const fieldErrors = ref<Record<string, string>>({})

  /**
   * Clear the last failure.
   */
  function reset(): void {
    error.value = null
    fieldErrors.value = {}
  }

  /**
   * Send the request, surfacing any failure through `error`/`fieldErrors`.
   */
  async function run(options: RequestOptions = {}): Promise<T | null> {
    if (busy.value) {
      return null
    }

    const settings = { ...defaults, ...options }

    busy.value = true
    reset()

    try {
      const res = await fetch(url(), {
        method: settings.method ?? 'POST',
        headers: apiHeaders(contentTypeFor(settings)),
        body: encodeBody(settings),
      })

      const body = (await res.json().catch(() => ({}))) as T & ApiError

      if (!res.ok) {
        fieldErrors.value = body.errors ?? {}
        throw new Error(body.error ?? `HTTP ${res.status}`)
      }

      return body
    } catch (e: unknown) {
      error.value = e instanceof Error ? e.message : (settings.fallback ?? 'Request failed')

      return null
    } finally {
      busy.value = false
    }
  }

  return { busy, error, fieldErrors, run, reset }
}

/**
 * The Content-Type this payload needs, if any. FormData sets its own boundary.
 */
function contentTypeFor(options: RequestOptions): Record<string, string> {
  if (options.json !== undefined) {
    return { 'Content-Type': 'application/json' }
  }

  if (options.body === undefined || options.body instanceof FormData) {
    return {}
  }

  return { 'Content-Type': 'application/x-www-form-urlencoded' }
}

/**
 * Encode a payload for transport. JSON is stringified, FormData passes through,
 * and objects or `[name, value]` pairs become a form-encoded string.
 */
function encodeBody(options: RequestOptions): BodyInit | undefined {
  if (options.json !== undefined) {
    return JSON.stringify(options.json)
  }

  const body = options.body

  if (body === undefined || body instanceof FormData) {
    return body
  }

  const params = new URLSearchParams()

  if (Array.isArray(body)) {
    for (const [key, value] of body) {
      params.append(key, value)
    }
  } else {
    for (const [key, value] of Object.entries(body)) {
      if (value !== null && value !== undefined) {
        params.append(key, String(value))
      }
    }
  }

  return params.toString()
}
