import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import OverviewPage from '../src/features/Academic/pages/OverviewPage'

const auth = vi.hoisted(() => ({ user: { id: 1, layout: {} }, updateUser: vi.fn() }))
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: auth.user, updateUser: auth.updateUser }) }))
vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), put: vi.fn(), post: vi.fn(), delete: vi.fn() } }))
vi.mock('../src/features/Badges/useBadges', () => ({ default: () => ({ surveys: 0 }) }))

const wrap = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter><OverviewPage /></MemoryRouter></QueryClientProvider>)
const bar = () => within(screen.getByRole('navigation', { name: 'Overview' }))

beforeEach(async () => {
  vi.resetAllMocks()
  auth.user = { id: 1, layout: {} }
  await i18n.changeLanguage('en')
  api.get.mockReturnValue(new Promise(() => {})) // the data is not what this is about
  api.put.mockResolvedValue({ data: { data: {} } })
})

describe('shortcuts of the overview bar', () => {
  it('starts with the overview and the default shortcuts', () => {
    wrap()
    expect(bar().getByRole('link', { name: 'Overview' })).toBeInTheDocument()
    expect(bar().getByRole('link', { name: 'Students' })).toBeInTheDocument()
    expect(bar().getByRole('link', { name: 'Finance' })).toBeInTheDocument()
  })

  it('shows the shortcuts the principal chose, in his order', () => {
    auth.user = { id: 1, layout: { admin_shortcuts: { groups: [], items: ['/admin/payroll', '/admin/grades'] } } }
    wrap()
    const names = bar().getAllByRole('link').map((a) => a.textContent)
    expect(names).toEqual(['Overview', 'Payroll', 'Grades'])
  })

  it('the + opens the editor; adding a page saves it on the account with the defaults kept', async () => {
    wrap()
    fireEvent.click(screen.getByRole('button', { name: 'Add or remove shortcuts' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add Payroll' }))
    await waitFor(() => expect(api.put).toHaveBeenCalled())
    const [url, body] = api.put.mock.calls[0]
    expect(url).toBe('/auth/layout')
    expect(body.surface).toBe('admin_shortcuts')
    expect(body.order.items).toEqual(['/admin/students', '/admin/timetable', '/admin/finance', '/admin/staff', '/admin/attendance', '/admin/payroll'])
  })

  it('removes a shortcut and can go back to the default', async () => {
    auth.user = { id: 1, layout: { admin_shortcuts: { groups: [], items: ['/admin/students', '/admin/finance'] } } }
    wrap()
    fireEvent.click(screen.getByRole('button', { name: 'Add or remove shortcuts' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(within(dialog).getByRole('region', { name: 'In your bar' })).getByRole('button', { name: 'Remove Students' }))
    await waitFor(() => expect(api.put.mock.calls[0][1].order.items).toEqual(['/admin/finance']))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Back to the default' }))
    await waitFor(() => expect(api.put.mock.calls[1][1].order).toBeNull())
  })

  it('moves a shortcut earlier', async () => {
    auth.user = { id: 1, layout: { admin_shortcuts: { groups: [], items: ['/admin/students', '/admin/finance'] } } }
    wrap()
    fireEvent.click(screen.getByRole('button', { name: 'Add or remove shortcuts' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Move earlier Finance' }))
    await waitFor(() => expect(api.put.mock.calls[0][1].order.items).toEqual(['/admin/finance', '/admin/students']))
  })
})
