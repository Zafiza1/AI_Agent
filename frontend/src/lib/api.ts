const BASE_URL = (import.meta.env.VITE_API_URL as string | undefined) ?? '/api'

const TOKEN_KEY = 'amp.token'
const ORG_KEY = 'amp.organization'

function read(key: string): string | null {
  try {
    return localStorage.getItem(key)
  } catch {
    return null
  }
}

function write(key: string, value: string | null): void {
  try {
    if (value === null) localStorage.removeItem(key)
    else localStorage.setItem(key, value)
  } catch {
    // Storage unavailable (private mode); the session lasts for this tab only.
  }
}

let token = read(TOKEN_KEY)
let organizationId = read(ORG_KEY)
let unauthorizedHandler: (() => void) | null = null

export const session = {
  get token() {
    return token
  },
  set token(value: string | null) {
    token = value
    write(TOKEN_KEY, value)
  },
  get organizationId() {
    return organizationId
  },
  set organizationId(value: string | null) {
    organizationId = value
    write(ORG_KEY, value)
  },
  onUnauthorized(handler: () => void) {
    unauthorizedHandler = handler
  },
}

export class ApiError extends Error {
  readonly status: number
  readonly errors: Record<string, string[]>

  constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
    super(message)
    this.status = status
    this.errors = errors
  }

  /** First validation message for a field, if any. */
  field(name: string): string | undefined {
    return this.errors[name]?.[0]
  }
}

type Query = Record<string, string | number | boolean | null | undefined>

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  body?: unknown
  query?: Query
  /** Send the X-Organization-Id header (default true). */
  tenant?: boolean
}

export async function api<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { method = 'GET', body, query, tenant = true } = options

  const url = new URL(BASE_URL + path, window.location.origin)
  for (const [key, value] of Object.entries(query ?? {})) {
    if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, String(value))
  }

  const headers: Record<string, string> = { Accept: 'application/json' }
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  if (token) headers.Authorization = `Bearer ${token}`
  if (tenant && organizationId) headers['X-Organization-Id'] = organizationId

  let response: Response
  try {
    response = await fetch(url, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
    })
  } catch {
    throw new ApiError(0, 'Cannot reach the API. Check that the backend is running.')
  }

  const payload = response.status === 204 ? null : await response.json().catch(() => null)

  if (!response.ok) {
    if (response.status === 401 && token) unauthorizedHandler?.()

    throw new ApiError(
      response.status,
      payload?.message ?? `Request failed with status ${response.status}`,
      payload?.errors ?? {},
    )
  }

  return payload as T
}
