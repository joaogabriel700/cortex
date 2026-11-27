// Writes the app's Content-Security-Policy for Caddy. Nuxt emits inline scripts whose content
// changes with every build, so they are allowed by hash. 'unsafe-inline' is never used for scripts.
import { createHash } from 'node:crypto'
import { readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { pathToFileURL } from 'node:url'

// Data blocks are not executed, so the policy does not apply to them.
const DATA_TYPES = /\btype\s*=\s*["']application\/(ld\+)?json["']/i

export function inlineScriptHashes(html) {
  const hashes = []

  for (const [, attributes, body] of html.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi)) {
    if (/\bsrc\s*=/i.test(attributes) || DATA_TYPES.test(attributes) || body === '') continue
    hashes.push(`'sha256-${createHash('sha256').update(body, 'utf8').digest('base64')}'`)
  }

  return hashes
}

export function buildPolicy(hashes) {
  return [
    "default-src 'self'",
    ['script-src', "'self'", ...[...new Set(hashes)].sort()].join(' '),
    "style-src 'self' 'unsafe-inline'",
    "img-src 'self' data: blob:",
    "font-src 'self'",
    "connect-src 'self'",
    "worker-src 'self'",
    "manifest-src 'self'",
    "object-src 'none'",
    "base-uri 'none'",
    "form-action 'self'",
    "frame-ancestors 'none'",
  ].join('; ')
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const [root, output] = process.argv.slice(2)
  const pages = readdirSync(root, { recursive: true }).filter((file) => file.endsWith('.html'))

  if (pages.length === 0) throw new Error(`No HTML files found in ${root}`)

  const hashes = pages.flatMap((file) => inlineScriptHashes(readFileSync(join(root, file), 'utf8')))

  // CSP_HEADER=Content-Security-Policy-Report-Only turns enforcement off while investigating.
  writeFileSync(output, `header {$CSP_HEADER:Content-Security-Policy} "${buildPolicy(hashes)}"\n`)
}
