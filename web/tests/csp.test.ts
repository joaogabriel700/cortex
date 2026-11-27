import { describe, expect, it } from 'vitest'
// @ts-expect-error plain build script without type declarations
import { buildPolicy, inlineScriptHashes } from '../scripts/csp.mjs'

describe('content security policy', () => {
  it('hashes only executable inline scripts', () => {
    const html = [
      '<script src="/_nuxt/entry.js"></script>',
      '<script type="application/json" id="data">{}</script>',
      '<script>alert(1)</script>',
      '<script type="importmap">{"imports":{}}</script>',
    ].join('')

    expect(inlineScriptHashes(html)).toHaveLength(2)
    expect(inlineScriptHashes(html)[0]).toBe("'sha256-bhHHL3z2vDgxUt0W3dWQOrprscmda2Y5pLsLg4GF+pI='")
  })

  it("never allows 'unsafe-inline' or 'unsafe-eval' for scripts", () => {
    const scripts = buildPolicy(["'sha256-x'"]).split('; ').find((part: string) => part.startsWith('script-src'))

    expect(scripts).toBe("script-src 'self' 'sha256-x'")
  })
})
