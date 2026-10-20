import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '../src/api/client'
import { isCentralHost, isValidSchoolCode, normalizeSchoolCode, schoolUrl } from '../src/features/Central/host'
import { registerSchool } from '../src/features/Central/api'

vi.mock('../src/api/client', () => ({ api: { post: vi.fn() } }))

describe('host helpers', () => {
  it('detects central hosts', () => {
    expect(isCentralHost('localhost')).toBe(true)
    expect(isCentralHost('127.0.0.1')).toBe(true)
    expect(isCentralHost('school2.localhost')).toBe(false)
  })

  it('builds a school url, keeping the port only when there is one', () => {
    expect(schoolUrl('alnoor', '/login', { protocol: 'http:', port: '5173' })).toBe('http://alnoor.localhost:5173/login')
    expect(schoolUrl('alnoor', '/login', { protocol: 'https:', port: '' })).toBe('https://alnoor.localhost/login')
  })

  it('validates school codes', () => {
    for (const ok of ['alnoor', 'al-noor2', 'a1b', 'a'.repeat(30)]) expect(isValidSchoolCode(ok)).toBe(true)
    for (const bad of ['Alnoor', 'al_noor', '-ab', 'ab-', 'ab', 'a'.repeat(31), '', 'al noor']) {
      expect(isValidSchoolCode(bad)).toBe(false)
    }
  })

  it('normalizes what the user typed', () => {
    expect(normalizeSchoolCode('  AlNoor ')).toBe('alnoor')
  })
})

describe('registerSchool', () => {
  beforeEach(() => vi.resetAllMocks())

  it('posts the payload to the central register endpoint', async () => {
    api.post.mockResolvedValue({ data: { data: { code: 'alnoor', domain: 'alnoor.localhost', login_path: '/login' } } })
    const payload = { school_name: 'X', code: 'alnoor' }
    const result = await registerSchool(payload)
    expect(api.post).toHaveBeenCalledWith('/central/register', payload)
    expect(result).toEqual({ code: 'alnoor', domain: 'alnoor.localhost', login_path: '/login' })
  })
})

describe('production configuration', () => {
  afterEach(() => {
    vi.unstubAllEnvs()
    vi.resetModules()
    vi.restoreAllMocks()
  })

  it('logs a loud error when a production build has no central hosts configured', async () => {
    vi.stubEnv('PROD', true)
    vi.stubEnv('VITE_CENTRAL_HOSTS', '')
    vi.stubEnv('VITE_BASE_DOMAIN', '')
    const error = vi.spyOn(console, 'error').mockImplementation(() => {})
    vi.resetModules()
    await import('../src/features/Central/host')
    expect(error).toHaveBeenCalledWith(expect.stringContaining('VITE_CENTRAL_HOSTS'))
    expect(error).toHaveBeenCalledWith(expect.stringContaining('VITE_BASE_DOMAIN'))
  })

  it('stays quiet when configured, and in development', async () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {})
    vi.stubEnv('PROD', true)
    vi.stubEnv('VITE_CENTRAL_HOSTS', 'madrasti.com,www.madrasti.com')
    vi.stubEnv('VITE_BASE_DOMAIN', 'madrasti.com')
    vi.resetModules()
    await import('../src/features/Central/host')
    vi.stubEnv('PROD', false)
    vi.stubEnv('VITE_CENTRAL_HOSTS', '')
    vi.resetModules()
    await import('../src/features/Central/host')
    expect(error).not.toHaveBeenCalled()
  })
})
