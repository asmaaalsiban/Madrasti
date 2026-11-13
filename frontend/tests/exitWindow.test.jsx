import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes, createMemoryRouter, RouterProvider } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api, getToken, setToken, setExitWindow } from '../src/api/client'
import saApi from '../src/features/SuperAdmin/saApi'
import SchoolPage from '../src/features/SuperAdmin/SchoolPage'
import DownloadYourDataPage from '../src/features/Auth/DownloadYourDataPage'
import { RequireAuth } from '../src/app/guards'
import { clearSchool, goTo, setSchool } from '../src/tenant/school'

vi.mock('../src/tenant/school', async (orig) => ({ ...(await orig()), goTo: vi.fn() }))
vi.mock('../src/features/SuperAdmin/saApi', async (orig) => ({
  ...(await orig()),
  default: { get: vi.fn(), post: vi.fn(), patch: vi.fn() },
}))
const mockSchool = vi.hoisted(() => ({ current: { name: 'Al Noor', logo_url: null, exit_window_ends_at: null } }))
vi.mock('../src/tenant/useSchool', () => ({ useSchool: () => ({ data: mockSchool.current, isLoading: false }) }))
const mockAuth = vi.hoisted(() => ({ user: { id: 1, role: 'admin' }, logout: vi.fn() }))
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: mockAuth.user, loading: false, logout: mockAuth.logout }) }))

// The school API: every request is recorded and answered from `answers` (by "METHOD url"), 200 with {} otherwise.
let answers
let calls
api.defaults.adapter = (config) => {
  calls.push(config)
  const hit = answers[`${config.method.toUpperCase()} ${config.url}`] ?? { status: 200, data: {} }
  const response = { data: hit.data, status: hit.status ?? 200, statusText: '', headers: {}, config }
  return response.status < 400 ? Promise.resolve(response) : Promise.reject(Object.assign(new Error('failed'), { response, config, isAxiosError: true }))
}

const client = () => new QueryClient({ defaultOptions: { queries: { retry: false } } })
const wrap = (ui, path = '/', route = '/') => render(
  <QueryClientProvider client={client()}>
    <MemoryRouter initialEntries={[path]}><Routes><Route path={route} element={ui} /></Routes></MemoryRouter>
  </QueryClientProvider>,
)

beforeEach(async () => {
  vi.resetAllMocks()
  calls = []
  answers = {}
  clearSchool()
  localStorage.clear()
  setSchool('alnoor')
  setToken('abc')
  setExitWindow(false)
  mockSchool.current = { name: 'Al Noor', logo_url: null, exit_window_ends_at: null }
  mockAuth.user = { id: 1, role: 'admin' }
  window.history.replaceState({}, '', '/admin')
  await i18n.changeLanguage('en')
})

describe('DownloadYourDataPage', () => {
  it('shows the days left and downloads the package', async () => {
    mockSchool.current = { name: 'Al Noor', logo_url: null, exit_window_ends_at: '2026-12-01T10:00:00+00:00' }
    answers['GET /exit-export'] = { data: { ends_at: '2026-12-01T10:00:00+00:00', seconds_left: 3 * 86400 + 5 * 3600 + 120, ready: true } }
    answers['GET /exit-export/package'] = { data: new Blob(['zip']) }
    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})

    wrap(<DownloadYourDataPage />)

    expect(await screen.findByText(/3 days/)).toBeInTheDocument()
    expect(screen.getByText(/5 hours/)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Download the data package' }))

    await waitFor(() => expect(click).toHaveBeenCalled())
    const get = calls.find((c) => c.url === '/exit-export/package')
    expect(get.responseType).toBe('blob')
    expect(URL.createObjectURL).toHaveBeenCalled()
  })

  it('waits while the package is not ready', async () => {
    mockSchool.current = { name: 'Al Noor', logo_url: null, exit_window_ends_at: '2026-12-01T10:00:00+00:00' }
    answers['GET /exit-export'] = { data: { ends_at: '2026-12-01T10:00:00+00:00', seconds_left: 3600, ready: false } }
    wrap(<DownloadYourDataPage />)
    expect(await screen.findByText(/being prepared/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Download the data package' })).toBeDisabled()
  })
})

describe('the exit window guard', () => {
  const renderAt = (path) => {
    const router = createMemoryRouter([
      { path: '/login', element: <p>login page</p> },
      { element: <RequireAuth />, children: [{ path: '/admin', element: <p>admin area</p> }, { path: '/download-your-data', element: <p>download page</p> }] },
    ], { initialEntries: [path] })
    render(<RouterProvider router={router} />)
  }

  it('an administrator in an exit window is sent to the download page', () => {
    mockSchool.current = { name: 'Al Noor', exit_window_ends_at: '2026-12-01T10:00:00+00:00' }
    renderAt('/admin')
    expect(screen.getByText('download page')).toBeInTheDocument()
    expect(screen.queryByText('admin area')).not.toBeInTheDocument()
  })

  it('an administrator who still has the default password changes it first, then gets the download page', () => {
    mockSchool.current = { name: 'Al Noor', exit_window_ends_at: '2026-12-01T10:00:00+00:00' }
    mockAuth.user = { id: 1, role: 'admin', must_change_password: true }
    const router = createMemoryRouter([
      { element: <RequireAuth />, children: [{ path: '/admin', element: <p>admin area</p> }, { path: '/download-your-data', element: <p>download page</p> }, { path: '/change-password', element: <p>change password page</p> }] },
    ], { initialEntries: ['/admin'] })
    render(<RouterProvider router={router} />)
    expect(screen.getByText('change password page')).toBeInTheDocument()
    expect(screen.queryByText('download page')).not.toBeInTheDocument()
  })

  it('an administrator of a school that is not leaving keeps the app', () => {
    renderAt('/admin')
    expect(screen.getByText('admin area')).toBeInTheDocument()
  })
})

describe('api client in an exit window', () => {
  const blocked = async () => {
    answers['GET /students'] = { status: 403, data: { code: 'school_suspended' } }
    await expect(api.get('/students')).rejects.toBeTruthy()
  }

  it('keeps the administrator signed in on the download page when a background call is blocked', async () => {
    setExitWindow(true)
    window.history.replaceState({}, '', '/download-your-data')
    await blocked()
    expect(getToken()).toBe('abc')
    expect(goTo).not.toHaveBeenCalled()
  })

  it('still signs out outside an exit window', async () => {
    window.history.replaceState({}, '', '/download-your-data')
    await blocked()
    expect(getToken()).toBeNull()
    expect(goTo).toHaveBeenCalledWith('/suspended')
  })

  it('still signs out in an exit window on any other page', async () => {
    setExitWindow(true)
    await blocked()
    expect(getToken()).toBeNull()
  })
})

describe('SchoolPage exit section', () => {
  const leaving = {
    id: 'alnoor', name: 'Al Noor', state: 'exit_window', is_active: true, default_locale: 'ar',
    subscription_ends_at: null, max_students: null, students_count: 1, staff_count: 1,
    last_backup_at: null, exit_window_ends_at: '2026-12-01T10:00:00.000000Z', closed_at: null, domains: [],
  }
  const serve = (state, steps) => saApi.get.mockImplementation((url) => Promise.resolve({
    data: url.endsWith('/audit') ? { data: [] } : url.endsWith('/exit-steps') ? { data: steps } : /^\/tenants\/[^/]+$/.test(url) ? { data: { ...leaving, ...state } } : { data: [{ ...leaving, ...state }], meta: { current_page: 1, last_page: 1, total: 1 } },
  }))
  const show = () => wrap(<SchoolPage />, '/super-admin/schools/alnoor', '/super-admin/schools/:id')

  it('shows the two steps with their status and a retry on a failed one', async () => {
    serve({}, [{ step: 'archive', status: 'done', error: null }, { step: 'package', status: 'failed', error: 'disk full' }])
    saApi.post.mockResolvedValue({ data: { data: [] } })
    show()

    const section = (await screen.findByRole('heading', { name: 'Data download' })).closest('section')
    expect(await within(section).findByText('Full archive')).toBeInTheDocument()
    expect(within(section).getByText('Done')).toBeInTheDocument()
    expect(within(section).getByText('Failed')).toBeInTheDocument()
    expect(within(section).getByText('disk full')).toBeInTheDocument()
    expect(within(section).getAllByRole('button', { name: 'Retry' })).toHaveLength(1)

    fireEvent.click(within(section).getByRole('button', { name: 'Retry' }))
    await waitFor(() => expect(saApi.post).toHaveBeenCalledWith('/tenants/alnoor/exit-steps/package/retry'))
  })

  it('opening the window sends the days', async () => {
    serve({ state: 'active', is_active: true, exit_window_ends_at: null }, [])
    saApi.post.mockResolvedValue({ data: { data: [] } })
    show()

    const section = (await screen.findByRole('heading', { name: 'Data download' })).closest('section')
    expect(within(section).getByLabelText('Days')).toHaveValue(14)
    fireEvent.change(within(section).getByLabelText('Days'), { target: { value: '21' } })
    fireEvent.click(within(section).getByRole('button', { name: 'Allow data download' }))
    expect(saApi.post).not.toHaveBeenCalled() // it asks first: this blocks everybody but the administrators
    fireEvent.click(await screen.findByRole('button', { name: 'Allow the download' }))
    await waitFor(() => expect(saApi.post).toHaveBeenCalledWith('/tenants/alnoor/exit-window', { days: 21 }))
  })

  it('offers a retry for a step that is stuck, not only for a failed one', async () => {
    serve({}, [{ step: 'archive', status: 'done', error: null }, { step: 'package', status: 'running', error: null, stale: true }])
    saApi.post.mockResolvedValue({ data: { data: [] } })
    show()

    const section = (await screen.findByRole('heading', { name: 'Data download' })).closest('section')
    fireEvent.click(await within(section).findByRole('button', { name: 'Stuck: retry' }))
    await waitFor(() => expect(saApi.post).toHaveBeenCalledWith('/tenants/alnoor/exit-steps/package/retry'))
  })

  it('a step that is just running has no retry', async () => {
    serve({}, [{ step: 'archive', status: 'running', error: null, stale: false }, { step: 'package', status: 'pending', error: null, stale: false }])
    show()

    const section = (await screen.findByRole('heading', { name: 'Data download' })).closest('section')
    expect(await within(section).findByText('Full archive')).toBeInTheDocument()
    expect(within(section).queryByRole('button', { name: /retry/i })).not.toBeInTheDocument()
  })

  it('shows Reactivate (not Suspend) for a school in its exit window or closed, whose is_active is still true', async () => {
    for (const state of ['exit_window', 'closed']) {
      serve({ state, is_active: true }, [])
      const { unmount } = show()
      const card = (await screen.findByRole('heading', { name: 'Status' })).closest('section')
      expect(within(card).getByRole('button', { name: 'Reactivate' })).toBeInTheDocument()
      expect(within(card).queryByRole('button', { name: 'Suspend' })).not.toBeInTheDocument()
      unmount()
    }
  })

  it('shows Suspend for an active or expired school', async () => {
    for (const state of ['active', 'expired']) {
      serve({ state, is_active: true, exit_window_ends_at: null }, [])
      const { unmount } = show()
      const card = (await screen.findByRole('heading', { name: 'Status' })).closest('section')
      expect(within(card).getByRole('button', { name: 'Suspend' })).toBeInTheDocument()
      unmount()
    }
  })

  it('shows a dash instead of numbers for a school whose numbers could not be read', async () => {
    serve({ state: 'active', is_active: true, exit_window_ends_at: null, students_count: null, staff_count: null }, [])
    show()

    const card = (await screen.findByRole('heading', { name: 'Status' })).closest('section')
    expect(within(card).getAllByText(/—/).length).toBeGreaterThanOrEqual(2)
  })

  it('extend sends the new days', async () => {
    serve({}, [{ step: 'archive', status: 'done', error: null }, { step: 'package', status: 'done', error: null }])
    saApi.patch.mockResolvedValue({ data: { data: {} } })
    show()

    const section = (await screen.findByRole('heading', { name: 'Data download' })).closest('section')
    fireEvent.change(within(section).getByLabelText('Extend by (days)'), { target: { value: '7' } })
    fireEvent.click(within(section).getByRole('button', { name: 'Extend window' }))
    await waitFor(() => expect(saApi.patch).toHaveBeenCalledWith('/tenants/alnoor/exit-window', { days: 7 }))
  })

  it('downloads a finished step with the owner token', async () => {
    serve({}, [{ step: 'archive', status: 'done', error: null }, { step: 'package', status: 'done', error: null }])
    saApi.get.mockImplementation((url) => (url.endsWith('/package')
      ? Promise.resolve({ data: new Blob(['zip']) })
      : Promise.resolve({ data: url.endsWith('/audit') ? { data: [] } : url.endsWith('/exit-steps') ? { data: [{ step: 'archive', status: 'done' }, { step: 'package', status: 'done' }] } : /^\/tenants\/[^/]+$/.test(url) ? { data: leaving } : { data: [leaving], meta: {} } })))
    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    show()

    const section = (await screen.findByRole('heading', { name: 'Data download' })).closest('section')
    fireEvent.click(await within(section).findByRole('button', { name: 'Download package' }))
    await waitFor(() => expect(click).toHaveBeenCalled())
    expect(saApi.get).toHaveBeenCalledWith('/tenants/alnoor/package', { responseType: 'blob' })
  })
})
