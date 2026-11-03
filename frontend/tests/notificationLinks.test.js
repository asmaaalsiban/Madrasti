import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

// Every address the server puts in a notification must be a page the app has: a notification that opens "404" is a bug (this happened once
// when the class feed moved to an icon). The test reads the PHP code for the addresses and the router for the pages.
const walk = (dir) => readdirSync(dir).flatMap((f) => { const p = join(dir, f); return statSync(p).isDirectory() ? walk(p) : p.endsWith('.php') ? [p] : [] })
const router = readFileSync('src/app/router.jsx', 'utf8')
const pages = new Set([...router.matchAll(/path: '([^']+)'/g)].map((m) => m[1].replace(/^\//, '')))
const has = (role, rest) => pages.has(role === 'admin' ? `admin/${rest}` : rest)

describe('notification links', () => {
  const php = walk('../backend/app').map((f) => readFileSync(f, 'utf8')).join('\n')

  it('every literal address points at an existing page', () => {
    const links = [...new Set([...php.matchAll(/['"]\/(admin|teacher|parent|student)\/([a-zA-Z0-9_\-/{}$>]+)['"]/g)].map((m) => [m[1], m[2].replace(/\{\$[^}]+\}/g, ':id')]))]
    expect(links.length).toBeGreaterThan(10)
    // Addresses built from a variable (the sections, checked below) are skipped here.
    const missing = links.filter(([, rest]) => rest !== ':id' && !rest.endsWith('/:id')).filter(([role, rest]) => !rest.includes('{') && !has(role, rest.replace(/^children\/:id/, 'children/:id')) && !has(role, rest)).map(([r, p]) => `/${r}/${p}`)
    expect(missing).toEqual([])
  })

  it('every section a parent or a student is notified about (toSection) has a page for each of them', () => {
    const sections = [...new Set([...php.matchAll(/toSection\([^,]+,\s*'([a-z-]+)'/g)].map((m) => m[1]))]
    expect(sections.length).toBeGreaterThan(3)
    for (const s of sections) {
      const parentPage = s === 'stories' ? 'stories' : `children/:id/${s}`
      expect(pages.has(parentPage), `/parent/${parentPage}`).toBe(true)
      expect(pages.has(s), `/student/${s}`).toBe(true)
    }
  })
})
