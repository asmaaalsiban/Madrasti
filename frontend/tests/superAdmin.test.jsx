import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import saApi from '../src/features/SuperAdmin/saApi'
import SuperAdminLoginPage from '../src/features/SuperAdmin/SuperAdminLoginPage'
import SchoolsPage from '../src/features/SuperAdmin/SchoolsPage'
import SchoolPage from '../src/features/SuperAdmin/SchoolPage'
import AddSchoolPage from '../src/features/SuperAdmin/AddSchoolPage'

// Only the Axios instance is replaced: the token helpers stay real so the stored token can be checked.
vi.mock('../src/features/SuperAdmin/saApi', async (orig) => ({
  ...(await orig()),
  default: { get: vi.fn(), post: vi.fn(), patch: vi.fn() },
}))

const school = {
  id: 'alnoor', name: 'Al Noor', state: 'active', is_active: true, default_locale: 'ar',
  subscription_ends_at: '2027-06-30T00:00:00.000000Z', max_students: 50, students_count: 12, staff_count: 4,
  last_backup_at: null, exit_window_ends_at: null, closed_at: null, domains: [],
}

const wrap = (ui, path = '/', route = '/') => render(
  <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
    <MemoryRouter initialEntries={[path]}><Routes><Route path={route} element={ui} /></Routes></MemoryRouter>
  </QueryClientProvider>,
)

const serve = (rows = [school], audit = []) => saApi.get.mockImplementation((url) => Promise.resolve({
  data: url.endsWith('/audit') ? { data: audit } : { data: rows, meta: { current_page: 1, last_page: 1, total: rows.length } },
}))

beforeEach(async () => {
  vi.resetAllMocks()
  localStorage.clear()
  await i18n.changeLanguage('en')
})

describe('SuperAdminLoginPage', () => {
  const start = () => {
    const onSignedIn = vi.fn()
    wrap(<SuperAdminLoginPage onSignedIn={onSignedIn} />)
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'owner@madrasati.test' } })
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'a-very-long-password' } })
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }))
    return onSignedIn
  }

  it('login asks for the code after the password and stores the token', async () => {
    saApi.post.mockResolvedValueOnce({ data: { challenge: true } }).mockResolvedValueOnce({ data: { token: 'tok-1', user: { id: 1, name: 'Owner', email: 'owner@madrasati.test' } } })
    const onSignedIn = start()

    fireEvent.change(await screen.findByLabelText('6-digit code'), { target: { value: '123456' } })
    expect(saApi.post).toHaveBeenCalledWith('/auth/login', { email: 'owner@madrasati.test', password: 'a-very-long-password' })
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    await waitFor(() => expect(onSignedIn).toHaveBeenCalled())
    expect(saApi.post).toHaveBeenLastCalledWith('/auth/verify', { email: 'owner@madrasati.test', code: '123456' })
    expect(localStorage.getItem('sa-token')).toBe('tok-1')
  })

  it('a wrong code shows the error', async () => {
    saApi.post.mockResolvedValueOnce({ data: { challenge: true } }).mockRejectedValueOnce({ response: { status: 422, data: { errors: { code: ['The code is wrong or expired.'] } } } })
    const onSignedIn = start()

    fireEvent.change(await screen.findByLabelText('6-digit code'), { target: { value: '000000' } })
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(await screen.findByText('The code is wrong or expired.')).toBeInTheDocument()
    expect(onSignedIn).not.toHaveBeenCalled()
    expect(localStorage.getItem('sa-token')).toBeNull()
  })
})

describe('SchoolsPage', () => {
  it('the list shows state, end date and used / limit', async () => {
    serve()
    wrap(<SchoolsPage />)

    const row = (await screen.findByText('Al Noor')).closest('tr')
    expect(within(row).getByText('Active')).toBeInTheDocument()
    expect(within(row).getByText('30 Jun 2027')).toBeInTheDocument()
    expect(within(row).getByText('12 / 50')).toBeInTheDocument()
  })

  it('shows no limit as unlimited and filters by state', async () => {
    serve([{ ...school, max_students: null, subscription_ends_at: null }])
    wrap(<SchoolsPage />)
    expect(await screen.findByText('12 / Unlimited')).toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('Status'), { target: { value: 'suspended' } })
    await waitFor(() => expect(saApi.get).toHaveBeenLastCalledWith('/tenants', { params: expect.objectContaining({ state: 'suspended' }) }))
  })
})

describe('SchoolPage', () => {
  const open = async (rows) => {
    serve(rows, [{ id: 1, action: 'school.extend', actor: 'Owner', meta: { from: '2026-06-30', to: '2027-06-30' }, created_at: '2026-11-12T10:00:00+00:00' }])
    wrap(<SchoolPage />, '/super-admin/schools/alnoor', '/super-admin/schools/:id')
    await screen.findByDisplayValue('Al Noor')
  }

  it('suspend asks for confirmation and calls the endpoint', async () => {
    saApi.post.mockResolvedValue({ data: { data: { ...school, is_active: false, state: 'suspended' } } })
    await open()

    fireEvent.click(screen.getByRole('button', { name: 'Suspend' }))
    expect(saApi.post).not.toHaveBeenCalled()
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Suspend school' }))

    await waitFor(() => expect(saApi.post).toHaveBeenCalledWith('/tenants/alnoor/suspend'))
  })

  it('a suspended school offers reactivate, also after a confirmation', async () => {
    saApi.post.mockResolvedValue({ data: { data: school } })
    await open([{ ...school, is_active: false, state: 'suspended' }])

    fireEvent.click(screen.getByRole('button', { name: 'Reactivate' }))
    expect(saApi.post).not.toHaveBeenCalled()
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Reactivate school' }))

    await waitFor(() => expect(saApi.post).toHaveBeenCalledWith('/tenants/alnoor/reactivate'))
  })

  it('extend sends the new date', async () => {
    saApi.post.mockResolvedValue({ data: { data: school } })
    await open()

    fireEvent.change(screen.getByLabelText('New end date'), { target: { value: '2028-01-31' } })
    fireEvent.click(screen.getByRole('button', { name: 'Extend' }))

    await waitFor(() => expect(saApi.post).toHaveBeenCalledWith('/tenants/alnoor/extend', { subscription_ends_at: '2028-01-31' }))
  })

  it('saves the edited fields and lists the audit', async () => {
    saApi.patch.mockResolvedValue({ data: { data: school } })
    await open()
    expect(await screen.findByText('Subscription extended')).toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('Student limit'), { target: { value: '80' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(saApi.patch).toHaveBeenCalledWith('/tenants/alnoor', expect.objectContaining({ name: 'Al Noor', max_students: 80 })))
  })
})

describe('AddSchoolPage', () => {
  it('the add form sends limit and end date', async () => {
    saApi.post.mockResolvedValue({ data: { data: school } })
    wrap(<AddSchoolPage />)

    const type = (label, value) => fireEvent.change(screen.getByLabelText(label), { target: { value } })
    type('School name', 'Al Noor')
    type('School code', 'alnoor')
    type('Admin name', 'Sara')
    type('Admin email', 'sara@alnoor.test')
    type('Admin password', 'Secret123')
    type('Student limit', '50')
    type('Subscription end date', '2027-06-30')
    fireEvent.click(screen.getByRole('button', { name: 'Add school' }))

    await waitFor(() => expect(saApi.post).toHaveBeenCalledWith('/tenants', expect.objectContaining({
      id: 'alnoor', name: 'Al Noor', admin_name: 'Sara', admin_email: 'sara@alnoor.test', admin_password: 'Secret123', max_students: 50, subscription_ends_at: '2027-06-30',
    })))
  })

  it('shows the server error on the field', async () => {
    saApi.post.mockRejectedValue({ response: { status: 422, data: { errors: { id: ['The code is taken.'] } } } })
    wrap(<AddSchoolPage />)
    const type = (label, value) => fireEvent.change(screen.getByLabelText(label), { target: { value } })
    type('School name', 'Al Noor')
    type('School code', 'alnoor')
    type('Admin name', 'Sara')
    type('Admin email', 'sara@alnoor.test')
    type('Admin password', 'Secret123')
    fireEvent.click(screen.getByRole('button', { name: 'Add school' }))
    expect(await screen.findByText('The code is taken.')).toBeInTheDocument()
  })
})
