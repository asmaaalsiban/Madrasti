import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import WarningsPage from '../src/features/Warnings/pages/WarningsPage'
import ChildWarningsPage from '../src/features/Warnings/pages/ChildWarningsPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: { role: 'parent', children: [{ id: 4, name_ar: 'سامي', name_en: 'Sami' }] } }) }))
const client = () => new QueryClient({ defaultOptions: { queries: { retry: false } } })

const warning = (extra = {}) => ({ id: 1, subject_type: 'student', kind: 'manual', category: 'violence', level: 3, title: 'Hit a teacher', body: 'Details', issued_on: '2026-11-08', status: 'active', issued_by: 'Principal', student: { id: 4, name_ar: 'سامي', name_en: 'Sami' }, user: null, acknowledged_at: null, withdrawn_reason: null, ...extra })

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
})

describe('WarningsPage (admin)', () => {
  it('lists warnings, writes a new one for a searched student and withdraws one with a reason', async () => {
    api.get.mockImplementation(async (url) => {
      if (url === '/warnings') return { data: { data: [warning(), warning({ id: 2, status: 'pending', title: 'Fight' })], can_manage: true } }
      if (url === '/students') return { data: { data: [{ id: 4, name_ar: 'سامي', name_en: 'Sami', student_number: 'S-1' }] } }
      return { data: { data: [] } }
    })
    api.post.mockResolvedValue({ data: { data: {} } })
    render(<QueryClientProvider client={client()}><MemoryRouter><WarningsPage /></MemoryRouter></QueryClientProvider>)
    expect(await screen.findByText('Hit a teacher')).toBeInTheDocument()
    expect(screen.getByText('Waiting for approval', { selector: 'span' })).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Approve' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/warnings/2/approve'))

    fireEvent.click(screen.getByRole('button', { name: 'New warning' }))
    const dialog = await screen.findByRole('dialog', { name: 'New warning' })
    fireEvent.change(within(dialog).getByLabelText(/Search a student/), { target: { value: 'sam' } })
    fireEvent.click(await within(dialog).findByRole('button', { name: /Sami · S-1/ }))
    fireEvent.change(within(dialog).getByLabelText('Level'), { target: { value: '3' } })
    fireEvent.change(within(dialog).getByLabelText('Title'), { target: { value: 'Broke a window' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Issue the warning' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/warnings', expect.objectContaining({ subject_type: 'student', student_id: 4, level: 3, title: 'Broke a window' })))
  })

  it('withdraws a warning only with a reason', async () => {
    api.get.mockResolvedValue({ data: { data: [warning()], can_manage: true } })
    api.post.mockResolvedValue({ data: { data: {} } })
    render(<QueryClientProvider client={client()}><MemoryRouter><WarningsPage /></MemoryRouter></QueryClientProvider>)
    fireEvent.click(await screen.findByRole('button', { name: 'Withdraw' }))
    const dialog = await screen.findByRole('dialog', { name: 'Withdraw this warning' })
    fireEvent.change(within(dialog).getByLabelText('Reason'), { target: { value: 'Wrong student' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Withdraw' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/warnings/1/withdraw', { reason: 'Wrong student' }))
  })
})

describe('Warning rules (admin)', () => {
  const rules = [{ id: 1, subject_type: 'student', name: '3 days absent', metric: 'absent_days', threshold: 3, window_days: 30, category: 'absence', level: 2, title: 'Absent', body: null, auto_send: false, is_active: true }]
  beforeEach(() => {
    api.get.mockImplementation(async (url) => (url === '/warnings/rules' ? { data: { data: rules } } : { data: { data: [], can_manage: true } }))
    api.post.mockResolvedValue({ data: { data: { created: 1, pending: 2 } } })
    api.put.mockResolvedValue({ data: { data: {} } })
  })

  it('shows the rules as sentences, creates one and runs them now', async () => {
    api.put = vi.fn().mockResolvedValue({ data: { data: {} } })
    render(<QueryClientProvider client={client()}><MemoryRouter><WarningsPage /></MemoryRouter></QueryClientProvider>)
    fireEvent.click(await screen.findByRole('tab', { name: 'Rules' }))
    expect(await screen.findByText(/When days absent reaches 3 within 30 days: First warning/)).toBeInTheDocument()
    expect(screen.getByText(/waits for approval/)).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Run the rules now' }))
    expect(await screen.findByRole('status')).toHaveTextContent('1 warning(s) sent, 2 waiting for your approval')

    fireEvent.click(screen.getByRole('button', { name: 'New rule' }))
    const dialog = await screen.findByRole('dialog', { name: 'New rule' })
    fireEvent.change(within(dialog).getByLabelText('Rule name'), { target: { value: 'Late 5 times' } })
    fireEvent.change(within(dialog).getByLabelText('Measure'), { target: { value: 'late_count' } })
    fireEvent.change(within(dialog).getByLabelText('reaches'), { target: { value: '5' } })
    fireEvent.change(within(dialog).getByLabelText('Title'), { target: { value: 'Late too often' } })
    fireEvent.click(within(dialog).getByLabelText(/Send it at once/))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/warnings/rules', expect.objectContaining({ metric: 'late_count', threshold: 5, auto_send: true, title: 'Late too often' })))
  })

  it('switches a rule off', async () => {
    render(<QueryClientProvider client={client()}><MemoryRouter><WarningsPage /></MemoryRouter></QueryClientProvider>)
    fireEvent.click(await screen.findByRole('tab', { name: 'Rules' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Switch off' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/warnings/rules/1', expect.objectContaining({ is_active: false })))
  })
})

describe('ChildWarningsPage (parent)', () => {
  it('shows the warning on the childs page and lets the parent say they have seen it', async () => {
    api.get.mockResolvedValue({ data: { data: [warning()] } })
    api.post.mockResolvedValue({ data: { data: {} } })
    render(<QueryClientProvider client={client()}><MemoryRouter initialEntries={['/parent/children/4/warnings']}><Routes><Route path="/parent/children/:id/warnings" element={<ChildWarningsPage />} /></Routes></MemoryRouter></QueryClientProvider>)
    expect(await screen.findByText('Warnings of Sami')).toBeInTheDocument()
    expect(await screen.findByText('Hit a teacher')).toBeInTheDocument()
    expect(screen.getByText('Second warning')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'I have seen it' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/warnings/1/acknowledge'))
  })
})
