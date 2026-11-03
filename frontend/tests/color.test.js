import { describe, expect, it } from 'vitest'
import { contrast, readableBrand } from '../src/tenant/color'

describe('the school colour is made readable', () => {
  it('darkens a light blue until white text and cream paper both reach 4.5:1', () => {
    const c = readableBrand('#2e96ff')
    expect(c).not.toBe('#2e96ff')
    expect(contrast(c, '#ffffff')).toBeGreaterThanOrEqual(4.5)
    expect(contrast(c, '#f9f7f0')).toBeGreaterThanOrEqual(4.5)
  })

  it('keeps a colour that is already dark enough, and ignores nonsense', () => {
    expect(readableBrand('#13426f')).toBe('#13426f')
    expect(readableBrand('red')).toBeNull()
    expect(readableBrand(null)).toBeNull()
  })

  it('every default colour of the app is readable', () => {
    expect(contrast('#006ddb', '#ffffff')).toBeGreaterThanOrEqual(4.5)
    expect(contrast('#006ddb', '#f9f7f0')).toBeGreaterThanOrEqual(4.5)
  })
})
