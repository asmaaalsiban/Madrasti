import { afterEach, describe, expect, it } from 'vitest'
import { applyLiteMode, isLowEndDevice } from '../src/lite'

const set = (name, value) => Object.defineProperty(navigator, name, { value, configurable: true })

afterEach(() => {
  delete document.documentElement.dataset.lite
  ;['deviceMemory', 'hardwareConcurrency', 'connection'].forEach((n) => set(n, undefined))
})

describe('lite mode', () => {
  it('is on for little memory, few cores, or the data-saver setting', () => {
    set('deviceMemory', 2)
    expect(isLowEndDevice()).toBe(true)
    set('deviceMemory', 8)
    set('hardwareConcurrency', 2)
    expect(isLowEndDevice()).toBe(true)
    set('hardwareConcurrency', 8)
    set('connection', { saveData: true })
    expect(isLowEndDevice()).toBe(true)
  })

  it('is off for a strong device, and marks the page when on', () => {
    set('deviceMemory', 8)
    set('hardwareConcurrency', 8)
    set('connection', { saveData: false })
    applyLiteMode()
    expect(document.documentElement.dataset.lite).toBeUndefined()
    set('deviceMemory', 2)
    applyLiteMode()
    expect(document.documentElement.dataset.lite).toBe('true')
  })
})
