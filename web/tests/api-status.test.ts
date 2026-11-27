import { describe, expect, it } from 'vitest'
import { fetchApiStatus } from '../app/utils/api-status'

describe('fetchApiStatus', () => {
  it('is ok only when the API says so', async () => {
    await expect(fetchApiStatus(async () => ({ status: 'ok' }))).resolves.toBe('ok')
  })

  it.each([null, {}, { status: 'error' }, '<html></html>'])('treats %j as unavailable', async (body) => {
    await expect(fetchApiStatus(async () => body)).resolves.toBe('unavailable')
  })

  it('treats a failed request as unavailable', async () => {
    await expect(fetchApiStatus(() => Promise.reject(new Error('offline')))).resolves.toBe('unavailable')
  })
})
