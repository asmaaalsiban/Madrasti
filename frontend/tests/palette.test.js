import { describe, expect, it } from 'vitest'
import { paletteFromPixels } from '../src/tenant/palette'
import { contrast } from '../src/tenant/color'

// Builds RGBA pixels: [[r,g,b,count], ...]
const pixels = (list) => Uint8ClampedArray.from(list.flatMap(([r, g, b, n, a = 255]) => Array.from({ length: n }).flatMap(() => [r, g, b, a])))

describe('colours of a logo', () => {
  it('finds a pink main colour and a navy dark colour (white background ignored)', () => {
    const p = paletteFromPixels(pixels([[255, 255, 255, 5000], [224, 69, 123, 900], [18, 40, 90, 500]]))
    const [r, g, b] = [1, 3, 5].map((i) => parseInt(p.primary.slice(i, i + 2), 16))
    expect(r).toBeGreaterThan(200)
    expect(g).toBeLessThan(120)
    expect(b).toBeGreaterThan(80)
    const dark = [1, 3, 5].map((i) => parseInt(p.dark.slice(i, i + 2), 16))
    expect(dark[2]).toBeGreaterThan(dark[0]) // navy: blue is the strongest
    expect(contrast(p.dark, '#ffffff')).toBeGreaterThanOrEqual(7)
    expect(p.swatches).toContain(p.primary)
  })

  it('takes the biggest vivid colour, and ignores transparent pixels', () => {
    const p = paletteFromPixels(pixels([[0, 180, 110, 300], [230, 140, 0, 800], [255, 0, 0, 9000, 0]]))
    expect(p.primary.slice(1, 3) > p.primary.slice(5, 7)).toBe(true) // orange: red above blue
    expect(p.swatches.length).toBe(2)
  })

  it('gives null for a grey, black and white logo', () => {
    expect(paletteFromPixels(pixels([[255, 255, 255, 2000], [10, 10, 10, 500], [128, 128, 128, 300]]))).toBeNull()
  })

  it('makes a dark colour from the main one when the logo has no dark part', () => {
    const p = paletteFromPixels(pixels([[240, 190, 20, 1500]]))
    expect(contrast(p.dark, '#ffffff')).toBeGreaterThanOrEqual(7)
  })
})
