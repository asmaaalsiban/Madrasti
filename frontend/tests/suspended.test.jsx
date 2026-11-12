import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api, getToken, setToken } from '../src/api/client'
import i18n from '../src/i18n'
import SuspendedPage from '../src/features/Auth/SuspendedPage'
import { clearSchool, goTo, setSchool } from '../src/tenant/school'

vi.mock('../src/tenant/school', async (orig) => ({ ...(await orig()), goTo: vi.fn() }))
vi.mock('../src/tenant/useSchool', () => ({ useSchool: () => ({ data: { name: 'Al Noor School', logo_url: null } }) }))

let answer
api.defaults.adapter = (config) => {
  const { status, data } = answer
  const response = { data, status, statusText: '', headers: {}, config }
  return status < 400 ? Promise.resolve(response) : Promise.reject(Object.assign(new Error('failed'), { response, config, isAxiosError: true }))
}

beforeEach(async () => {
  vi.clearAllMocks()
  clearSchool()
  localStorage.clear()
  setSchool('alnoor')
  setToken('abc')
  window.history.replaceState({}, '', '/admin')
  await i18n.changeLanguage('en')
})

describe('api client, blocked school', () => {
  it('clears the token and goes to /suspended on a 403 school_suspended', async () => {
    answer = { status: 403, data: { code: 'school_suspended', message: 'x' } }
    const expired = vi.fn()
    window.addEventListener('auth:expired', expired)
    await expect(api.get('/students')).rejects.toBeTruthy()
    window.removeEventListener('auth:expired', expired)
    expect(getToken()).toBeNull()
    expect(expired).toHaveBeenCalled()
    expect(goTo).toHaveBeenCalledWith('/suspended')
  })

  it('does not redirect again when already on /suspended', async () => {
    window.history.replaceState({}, '', '/suspended')
    answer = { status: 403, data: { code: 'school_suspended' } }
    await expect(api.get('/school/other')).rejects.toBeTruthy()
    expect(goTo).not.toHaveBeenCalled()
  })

  it('leaves the token alone on a 403 without that code', async () => {
    answer = { status: 403, data: { message: 'Forbidden' } }
    await expect(api.get('/students')).rejects.toBeTruthy()
    expect(getToken()).toBe('abc')
    expect(goTo).not.toHaveBeenCalled()
  })
})

describe('SuspendedPage', () => {
  const show = () => render(<MemoryRouter><SuspendedPage /></MemoryRouter>)

  it('shows the school name, the explanation and a sign-in link in English', () => {
    show()
    expect(screen.getAllByText('Al Noor School').length).toBeGreaterThan(0)
    expect(screen.getByText(/subscription has ended/i)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Sign in again' })).toHaveAttribute('href', '/login')
  })

  it('shows the school name and the explanation in Arabic', async () => {
    await i18n.changeLanguage('ar')
    show()
    expect(screen.getAllByText('Al Noor School').length).toBeGreaterThan(0)
    expect(screen.getByText(/انتهى اشتراك/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'تسجيل الدخول مرة أخرى' })).toBeInTheDocument()
  })
})
