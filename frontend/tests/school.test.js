import { beforeEach, describe, expect, it, vi } from 'vitest'
import { clearSchool, getSchool, isValidSchoolCode, normalizeSchoolCode, setSchool, watchSchoolChanges } from '../src/tenant/school'

beforeEach(() => {
  clearSchool()
  localStorage.clear()
})

describe('selected school', () => {
  it('starts empty, remembers a school and can forget it', () => {
    expect(getSchool()).toBeNull()
    setSchool('alnoor')
    expect(getSchool()).toBe('alnoor')
    clearSchool()
    expect(getSchool()).toBeNull()
  })

  it('validates school codes like the backend', () => {
    for (const ok of ['alnoor', 'al-noor2', 'a1b', 'a'.repeat(30)]) expect(isValidSchoolCode(ok)).toBe(true)
    for (const bad of ['Alnoor', 'al_noor', '-ab', 'ab-', 'ab', 'a'.repeat(31), '', 'al noor']) {
      expect(isValidSchoolCode(bad)).toBe(false)
    }
  })

  it('normalizes what the user typed', () => {
    expect(normalizeSchoolCode('  AlNoor ')).toBe('alnoor')
  })
})

describe('several tabs', () => {
  it('keeps talking to the school this page loaded with when another tab changes the stored school', () => {
    setSchool('school-x')
    localStorage.setItem('school', 'school-y') // what another tab does
    expect(getSchool()).toBe('school-x')
  })

  it('tells the page to reload when another tab changes or clears the school', () => {
    const onChange = vi.fn()
    const stop = watchSchoolChanges(onChange)
    window.dispatchEvent(new StorageEvent('storage', { key: 'token:school-x', newValue: 't' }))
    expect(onChange).not.toHaveBeenCalled()
    window.dispatchEvent(new StorageEvent('storage', { key: 'school', newValue: 'school-y' }))
    window.dispatchEvent(new StorageEvent('storage', { key: null }))
    expect(onChange).toHaveBeenCalledTimes(2)
    stop()
    window.dispatchEvent(new StorageEvent('storage', { key: 'school', newValue: 'z-school' }))
    expect(onChange).toHaveBeenCalledTimes(2)
  })
})
