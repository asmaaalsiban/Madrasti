import { describe, expect, it } from 'vitest'
import i18n from '../src/i18n'

describe('i18n direction', () => {
  it('defaults to Arabic RTL and switches to LTR for English', async () => {
    expect(document.documentElement.dir).toBe('rtl')
    await i18n.changeLanguage('en')
    expect(document.documentElement.dir).toBe('ltr')
    expect(document.documentElement.lang).toBe('en')
    await i18n.changeLanguage('ar')
  })
})
