import { afterEach, describe, expect, it } from 'vitest'
import { applyTheme, clearTheme } from '../src/tenant/theme'

const get = (v) => document.documentElement.style.getPropertyValue(v)
afterEach(clearTheme)

describe('school theme', () => {
  it('sets the brand, the dark colour and the pale tints worked out from them', () => {
    applyTheme({ primary: '#0a5a9c', dark: '#12285a' })
    expect(get('--color-brand')).toBe('#0a5a9c')
    expect(get('--color-deep-harbor')).toBe('#12285a')
    expect(get('--color-sky-tint')).toContain('var(--color-brand)')
    expect(get('--lp-navy')).toContain('var(--color-deep-harbor)')
  })

  it('darkens a light colour so text stays readable', () => {
    applyTheme({ primary: '#ffb6c1', dark: '#12285a' })
    expect(get('--color-brand')).not.toBe('#ffb6c1')
  })

  it('keeps the default navy when only the main colour is given, and clears everything on reset', () => {
    applyTheme({ primary: '#0a5a9c' })
    expect(get('--color-deep-harbor')).toBe('')
    applyTheme({ primary: '#0a5a9c', dark: '#12285a' })
    clearTheme()
    expect(get('--color-brand')).toBe('')
    expect(get('--lp-glow')).toBe('')
  })

  it('does nothing for a bad colour', () => {
    applyTheme({ primary: 'pink', dark: '#12285a' })
    expect(get('--color-brand')).toBe('')
  })
})
