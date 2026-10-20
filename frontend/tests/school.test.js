import { beforeEach, describe, expect, it } from 'vitest'
import { clearSchool, getSchool, isValidSchoolCode, normalizeSchoolCode, setSchool } from '../src/tenant/school'

beforeEach(() => localStorage.clear())

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
