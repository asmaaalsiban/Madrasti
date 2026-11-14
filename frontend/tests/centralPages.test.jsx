import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, RouterProvider, createMemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { routesFor } from '../src/app/router'
import SwitchSchoolButton from '../src/components/shared/SwitchSchoolButton'
import { loginAdmin, publicPlans, registerSchool, registrationEnabled, schoolExists } from '../src/features/Central/api'
import FindSchoolPage from '../src/features/Central/FindSchoolPage'
import RegisterSchoolPage from '../src/features/Central/RegisterSchoolPage'
import WelcomePage from '../src/features/Central/WelcomePage'
import { clearSchool, getSchool, goTo, setSchool } from '../src/tenant/school'

vi.mock('../src/features/Central/api', () => ({ registerSchool: vi.fn(), loginAdmin: vi.fn(), schoolExists: vi.fn(), registrationEnabled: vi.fn(), publicPlans: vi.fn(() => Promise.resolve([])) }))
vi.mock('../src/tenant/school', async (orig) => ({ ...(await orig()), goTo: vi.fn() }))
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: null, loading: false }) }))
vi.mock('../src/tenant/useSchool', () => ({ useSchool: () => ({ data: { name: 'School Two' } }) }))

const withClient = (ui) => <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>{ui}</QueryClientProvider>
const renderPage = (ui) => render(withClient(<MemoryRouter>{ui}</MemoryRouter>))

beforeEach(async () => {
  vi.resetAllMocks()
  clearSchool()
  localStorage.clear()
  registrationEnabled.mockResolvedValue(true) // public registration is on unless a test says otherwise
  await i18n.changeLanguage('en')
})

describe('WelcomePage', () => {
  it('links to register and find-school in both languages', async () => {
    renderPage(<WelcomePage />)
    expect((await screen.findAllByRole('link', { name: 'Create account' }))[0]).toHaveAttribute('href', '/register')
    expect(screen.getAllByRole('link', { name: 'Sign in' })[0]).toHaveAttribute('href', '/find-school')
    await i18n.changeLanguage('ar')
    renderPage(<WelcomePage />)
    expect((await screen.findAllByRole('link', { name: 'إنشاء حساب' })).length).toBeGreaterThan(0)
  })

  it('shows the pricing plans the owner set, in the language of the page, and nothing when there are none', async () => {
    window.IntersectionObserver = class { observe() {} unobserve() {} disconnect() {} }
    publicPlans.mockResolvedValue([{ id: 1, name_ar: 'الأساسية', name_en: 'Basic', price_monthly: 50, price_yearly: 40, currency: 'JOD', max_students: 200, description_ar: 'لمدرسة صغيرة', description_en: 'Small school', features_ar: ['الحضور'], features_en: ['Attendance'], is_popular: true }])
    renderPage(<WelcomePage />)
    const pricing = within(await waitFor(() => { const el = document.getElementById('pricing'); if (!el) throw new Error('no pricing yet'); return el }))
    expect(pricing.getByText('Basic')).toBeInTheDocument()
    expect(pricing.getByText('Up to 200 students')).toBeInTheDocument()
    expect(pricing.getByText('Attendance')).toBeInTheDocument()
    expect(pricing.getByText('Small school')).toBeInTheDocument()
  })

  it('has no pricing section while there are no active plans', async () => {
    renderPage(<WelcomePage />)
    await screen.findAllByRole('link', { name: 'Sign in' })
    expect(screen.queryByText('Simple, transparent pricing')).not.toBeInTheDocument()
  })

  it('hides the register links when the server has public registration off', async () => {
    registrationEnabled.mockResolvedValue(false)
    renderPage(<WelcomePage />)
    expect((await screen.findAllByText('Sign in to your school with its code.')).length).toBeGreaterThan(0)
    expect(screen.queryByRole('link', { name: 'Create account' })).not.toBeInTheDocument()
    expect(screen.getAllByRole('link', { name: 'Sign in' })[0]).toHaveAttribute('href', '/find-school')
  })

  it('keeps the register links hidden when the server cannot be asked', async () => {
    registrationEnabled.mockRejectedValue(new Error('offline'))
    renderPage(<WelcomePage />)
    expect((await screen.findAllByText('Sign in to your school with its code.')).length).toBeGreaterThan(0)
    expect(screen.queryByRole('link', { name: 'Create account' })).not.toBeInTheDocument()
  })
})

describe('RegisterSchoolPage when registration is off', () => {
  it('shows that it is closed, with a way to sign in, and no form', async () => {
    registrationEnabled.mockResolvedValue(false)
    renderPage(<RegisterSchoolPage />)
    expect(await screen.findByRole('heading', { name: 'Registration is closed' })).toBeInTheDocument()
    expect(screen.queryByLabelText('School name')).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Sign in' })).toHaveAttribute('href', '/find-school')
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
  const submit = () => fireEvent.click(screen.getByRole('button', { name: 'Create account' }))

  it('creates the school, selects it, signs the admin in and opens the app on the same URL', async () => {
    registerSchool.mockResolvedValue({ code: 'alnoor' })
    loginAdmin.mockResolvedValue()
    renderPage(<RegisterSchoolPage />)
    await screen.findByLabelText('School name')
    fill()
    submit()
    await waitFor(() => expect(goTo).toHaveBeenCalledWith('/'))
    expect(registerSchool).toHaveBeenCalledWith({
      school_name: 'Al Noor', code: 'alnoor', admin_name: 'Admin', admin_email: 'a@alnoor.test',
      password: 'Secret123', password_confirmation: 'Secret123',
    })
    expect(getSchool()).toBe('alnoor')
    expect(loginAdmin).toHaveBeenCalledWith('a@alnoor.test', 'Secret123')
  })

  it('still opens the school when the automatic sign-in fails', async () => {
    registerSchool.mockResolvedValue({ code: 'alnoor' })
    loginAdmin.mockRejectedValue(new Error('network'))
    renderPage(<RegisterSchoolPage />)
    await screen.findByLabelText('School name')
    fill()
    submit()
    await waitFor(() => expect(goTo).toHaveBeenCalledWith('/'))
    expect(getSchool()).toBe('alnoor')
  })

  it('shows field errors from a 422 and does not select a school', async () => {
    registerSchool.mockRejectedValue({ response: { status: 422, data: { errors: { code: ['This school code is already taken.'] } } } })
    renderPage(<RegisterSchoolPage />)
    await screen.findByLabelText('School name')
    fill()
    submit()
    expect(await screen.findByText('This school code is already taken.')).toBeInTheDocument()
    expect(getSchool()).toBeNull()
    expect(goTo).not.toHaveBeenCalled()
  })

  it('shows the server message when registration is closed', async () => {
    registerSchool.mockRejectedValue({ response: { status: 403, data: { message: 'School registration is currently closed.' } } })
    renderPage(<RegisterSchoolPage />)
    await screen.findByLabelText('School name')
    fill()
    submit()
    expect(await screen.findByText('School registration is currently closed.')).toBeInTheDocument()
  })

  it('shows a generic error for other failures', async () => {
    registerSchool.mockRejectedValue({ response: { status: 500, data: { message: 'x' } } })
    renderPage(<RegisterSchoolPage />)
    await screen.findByLabelText('School name')
    fill()
    submit()
    expect(await screen.findByText('Something went wrong, please try again.')).toBeInTheDocument()
    expect(goTo).not.toHaveBeenCalled()
  })
})

describe('FindSchoolPage', () => {
  const enter = (value) => {
    renderPage(<FindSchoolPage />)
    fireEvent.change(screen.getByLabelText('School code'), { target: { value } })
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }))
  }

  it('normalizes the code, selects the school and opens its sign-in page', async () => {
    schoolExists.mockResolvedValue(true)
    enter('  AlNoor ')
    await waitFor(() => expect(goTo).toHaveBeenCalledWith('/login'))
    expect(schoolExists).toHaveBeenCalledWith('alnoor')
    expect(getSchool()).toBe('alnoor')
  })

  it('says so when the school does not exist and keeps nothing selected', async () => {
    schoolExists.mockResolvedValue(false)
    enter('nope')
    expect(await screen.findByText('School not found. Check the school code.')).toBeInTheDocument()
    expect(getSchool()).toBeNull()
    expect(goTo).not.toHaveBeenCalled()
  })

  it('rejects an invalid code without calling the server', () => {
    enter('al_noor')
    expect(screen.getByText('Use 3-30 lowercase letters, digits or hyphens.')).toBeInTheDocument()
    expect(schoolExists).not.toHaveBeenCalled()
  })

  it('shows a generic error when the server cannot be reached', async () => {
    schoolExists.mockRejectedValue(new Error('network'))
    enter('alnoor')
    expect(await screen.findByText('Something went wrong, please try again.')).toBeInTheDocument()
    expect(getSchool()).toBeNull()
  })
})

describe('SwitchSchoolButton', () => {
  it('forgets the selected school and opens the school code page', () => {
    setSchool('alnoor')
    render(<SwitchSchoolButton />)
    fireEvent.click(screen.getByRole('button', { name: 'Switch school' }))
    expect(getSchool()).toBeNull()
    expect(goTo).toHaveBeenCalledWith('/find-school')
  })

  it('has an Arabic label', async () => {
    await i18n.changeLanguage('ar')
    render(<SwitchSchoolButton />)
    expect(screen.getByRole('button', { name: 'تبديل المدرسة' })).toBeInTheDocument()
  })
})

describe('routesFor', () => {
  const at = (school, path = '/') =>
    render(withClient(<RouterProvider router={createMemoryRouter(routesFor(school), { initialEntries: [path] })} />))

  it('serves the welcome page when no school is selected', async () => {
    at(null)
    expect((await screen.findAllByRole('link', { name: 'Create account' }))[0]).toBeInTheDocument()
  })

  it('serves the school landing once a school is selected', () => {
    at('alnoor')
    expect(screen.getByText('Your whole school in one place')).toBeInTheDocument()
  })

  it('sends unknown paths back to the welcome page when no school is selected', async () => {
    at(null, '/nope')
    expect((await screen.findAllByRole('link', { name: 'Create account' }))[0]).toBeInTheDocument()
  })
})
