import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

// A scrolling table box must be position: relative, or the hidden "actions" header label (position: absolute) widens the whole page on a phone.
describe('scrolling table boxes', () => {
  it('contain their absolutely placed children', () => {
    const css = readFileSync('src/index.css', 'utf8')
    expect(css).toMatch(/\.overflow-x-auto\s*\{\s*position:\s*relative;?\s*\}/)
  })
})
