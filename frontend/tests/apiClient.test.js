import { beforeEach, describe, expect, it } from 'vitest'
import { api, getToken, setToken } from '../src/api/client'
import { clearSchool, setSchool } from '../src/tenant/school'

let sent
api.defaults.adapter = (config) => {
  sent = config
  return Promise.resolve({ data: {}, status: 200, statusText: 'OK', headers: {}, config })
}

beforeEach(() => {
  clearSchool()
  localStorage.clear()
  sent = null
})

describe('api client', () => {
  it('calls the same origin and sends no school header before a school is chosen', async () => {
    await api.get('/school')
    expect(api.defaults.baseURL).toBe('/api')
    expect(sent.headers['X-School']).toBeUndefined()
  })

  it('sends the selected school in X-School', async () => {
    setSchool('alnoor')
    await api.get('/school')
    expect(sent.headers['X-School']).toBe('alnoor')
  })

  it('keeps one token per school and never sends another school\'s token', async () => {
    setSchool('a-school')
    setToken('token-a')
    setSchool('b-school')
    expect(getToken()).toBeNull()
    await api.get('/auth/me')
    expect(sent.headers.Authorization).toBeUndefined()

    setSchool('a-school')
    expect(getToken()).toBe('token-a')
    await api.get('/auth/me')
    expect(sent.headers.Authorization).toBe('Bearer token-a')

    clearSchool()
    expect(getToken()).toBeNull()
  })
})
