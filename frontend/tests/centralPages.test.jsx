import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, RouterProvider, createMemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { routesFor } from '../src/app/router'
import { registerSchool } from '../src/features/Central/api'
import { redirectTo } from '../src/features/Central/host'
import FindSchoolPage from '../src/features/Central/FindSchoolPage'
import RegisterSchoolPage from '../src/features/Central/RegisterSchoolPage'
import WelcomePage from '../src/features/Central/WelcomePage'

vi.mock('../src/features/Central/api', () => ({ registerSchool: vi.fn() }))
vi.mock('../src/features/Central/host', async (orig) => ({ ...(await orig()), redirectTo: vi.fn() }))
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: null, loading: false }) }))
vi.mock('../src/tenant/useSchool', () => ({ useSchool: () => ({ data: { name: 'School Two' } }) }))

const renderPage = (ui) => render(<MemoryRouter>{ui}</MemoryRouter>)

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
})

describe('WelcomePage', () => {
  it('links to register and find-school in both languages', async () => {
    renderPage(<WelcomePage />)
    expect(screen.getByRole('link', { name: 'Create account' })).toHaveAttribute('href', '/register')
    expect(screen.getByRole('link', { name: 'Sign in' })).toHaveAttribute('href', '/find-school')
    await i18n.changeLanguage('ar')
    renderPage(<WelcomePage />)
    expect(screen.getAllByRole('link', { name: 'إنشاء حساب' }).length).toBeGreaterThan(0)
  })
})

describe('RegisterSchoolPage', () => {
  const fill = () => {
    fireEvent.change(screen.getByLabelText('School name'), { target: { value: 'Al Noor' } })
    fireEvent.change(screen.getByLabelText('School code'), { target: { value: 'AlNoor' } })
    fireEvent.change(screen.getByLabelText('Admin name'), { target: { value: 'Admin' } })
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'a@alnoor.test' } })
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'Secret123' } })
    fireEvent.change(screen.getByLabelText('Confirm password'), { target: { value: 'Secret123' } })
  }

  it('posts the payload and redirects to the new school login', async () => {
    registerSchool.mockResolvedValue({ code: 'alnoor', domain: 'alnoor.localhost', login_path: '/login' })
    renderPage(<RegisterSchoolPage />)
    fill()
    fireEvent.click(screen.getByRole('button', { name: 'Create account' }))
    await waitFor(() => expect(redirectTo).toHaveBeenCalledWith(expect.stringMatching(/^https?:\/\/alnoor\.localhost.*\/login$/)))
    expect(registerSchool).toHaveBeenCalledWith({
      school_name: 'Al Noor', code: 'alnoor', admin_name: 'Admin', admin_email: 'a@alnoor.test',
      password: 'Secret123', password_confirmation: 'Secret123',
    })
  })

  it('shows field errors from a 422', async () => {
    registerSchool.mockRejectedValue({ response: { status: 422, data: { errors: { code: ['This school code is already taken.'] } } } })
    renderPage(<RegisterSchoolPage />)
    fill()
    fireEvent.click(screen.getByRole('button', { name: 'Create account' }))
    expect(await screen.findByText('This school code is already taken.')).toBeInTheDocument()
    expect(redirectTo).not.toHaveBeenCalled()
  })

  it('shows a generic error for other failures', async () => {
    registerSchool.mockRejectedValue({ response: { status: 500, data: { message: 'x' } } })
    renderPage(<RegisterSchoolPage />)
    fill()
    fireEvent.click(screen.getByRole('button', { name: 'Create account' }))
    expect(await screen.findByText('Something went wrong, please try again.')).toBeInTheDocument()
    expect(redirectTo).not.toHaveBeenCalled()
  })
})

describe('FindSchoolPage', () => {
  it('normalizes the code and redirects to that school login', () => {
    renderPage(<FindSchoolPage />)
    fireEvent.change(screen.getByLabelText('School code'), { target: { value: '  AlNoor ' } })
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }))
    expect(redirectTo).toHaveBeenCalledWith(expect.stringMatching(/^https?:\/\/alnoor\.localhost.*\/login$/))
  })

  it('rejects an invalid code without redirecting', () => {
    renderPage(<FindSchoolPage />)
    fireEvent.change(screen.getByLabelText('School code'), { target: { value: 'al_noor' } })
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }))
    expect(screen.getByText('Use 3-30 lowercase letters, digits or hyphens.')).toBeInTheDocument()
    expect(redirectTo).not.toHaveBeenCalled()
  })
})

describe('routesFor', () => {
  const at = (hostname, path = '/') =>
    render(<RouterProvider router={createMemoryRouter(routesFor(hostname), { initialEntries: [path] })} />)

  it('serves the welcome page on a central host', () => {
    at('localhost')
    expect(screen.getByRole('link', { name: 'Create account' })).toBeInTheDocument()
  })

  it('keeps the school landing on a school host', () => {
    at('school2.localhost')
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Your whole school in one place')
  })

  it('does not expose register on a school host and redirects unknown central paths home', () => {
    at('localhost', '/nope')
    expect(screen.getByRole('link', { name: 'Create account' })).toBeInTheDocument()
  })
})
