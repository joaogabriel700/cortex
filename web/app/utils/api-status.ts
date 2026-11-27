export type ApiStatus = 'ok' | 'unavailable'

/** Anything other than an explicit "ok" (network error, 503, unexpected body) is unavailable. */
export async function fetchApiStatus(request: () => Promise<unknown>): Promise<ApiStatus> {
  try {
    const body = (await request()) as { status?: unknown } | null

    return body?.status === 'ok' ? 'ok' : 'unavailable'
  } catch {
    return 'unavailable'
  }
}
