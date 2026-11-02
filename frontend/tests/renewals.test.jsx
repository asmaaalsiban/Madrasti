import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import RenewalsPage from '../src/features/Renewals/pages/RenewalsPage'
import { notificationText } from '../src/features/Notifications/NotificationBell'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))

const wrap = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter><RenewalsPage /></MemoryRouter></QueryClientProvider>)
const g = (id, n) => ({ id, name_ar: n, name_en: n })
const row = (id, name, status, extra = {}) => ({ id, status, decline_reason: null, student: { id, name_ar: name, name_en: name, student_number: `S${id}` }, from_classroom: '1-A', from_grade: g(1, 'Grade 1'), to_grade: g(2, 'Grade 2'), to_classroom: null, ...extra })
const payload = (rows, summary = {}) => ({ year: { id: 9, name: '2027/2028' }, current_year: { id: 8, name: '2026/2027' }, years: [{ id: 8, name: '2026/2027', is_current: true }, { id: 9, name: '2027/2028', is_current: false }], summary: { pending: 0, renewed: 0, declined: 0, graduating: 0, total: rows.length, ...summary }, data: rows })

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
})

describe('RenewalsPage', () => {
  it('opens the renewal when nobody is listed yet', async () => {
    api.get.mockResolvedValue({ data: payload([]) })
    api.post.mockResolvedValue({ data: { added: 4 } })
    wrap()
    fireEvent.click(await screen.findByRole('button', { name: 'Open renewal' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/renewals/open', { year_id: 9 }))
    expect(await screen.findByText('4 student(s) added.')).toBeInTheDocument()
  })

  it('lists the waiting students and renews all of them', async () => {
    api.get.mockResolvedValue({ data: payload([row(1, 'Ali', 'pending'), row(2, 'Dana', 'renewed', { to_classroom: '2-A' })], { pending: 1, renewed: 1 }) })
    api.post.mockResolvedValue({ data: { renewed: 1, failed: [] } })
    wrap()
    expect(await screen.findByText('Ali')).toBeInTheDocument()
    expect(screen.queryByText('Dana')).not.toBeInTheDocument() // the "waiting" tab is open
    fireEvent.click(screen.getByRole('button', { name: 'Renew all waiting (1)' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/renewals/renew-all', { year_id: 9 }))
    fireEvent.click(screen.getByRole('tab', { name: /Renewed/ }))
    expect(screen.getByText('Dana')).toBeInTheDocument()
  })

  it('renews one student into a chosen section', async () => {
    api.get.mockImplementation((url) => Promise.resolve({ data: url === '/renewals/sections' ? { data: [{ id: 5, name: '2-A', students: 3, capacity: 25, free: 22 }] } : payload([row(1, 'Ali', 'pending')], { pending: 1 }) }))
    api.post.mockResolvedValue({ data: {} })
    wrap()
    fireEvent.click(await screen.findByRole('button', { name: 'Renew' }))
    await screen.findByRole('option', { name: '2-A (3/25)' })
    fireEvent.change(screen.getByLabelText('Section'), { target: { value: '5' } })
    fireEvent.click(screen.getAllByRole('button', { name: 'Renew' }).at(-1))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/renewals/1/renew', { classroom_id: '5', grade_level_id: null, discount: null, transport_fee: null }))
  })

  it('declines with a reason and reopens a declined student', async () => {
    api.get.mockResolvedValue({ data: payload([row(1, 'Ali', 'pending'), row(2, 'Omar', 'declined', { decline_reason: 'Abroad' })], { pending: 1, declined: 1 }) })
    api.post.mockResolvedValue({ data: {} })
    wrap()
    fireEvent.click(await screen.findByRole('button', { name: 'Decline' }))
    fireEvent.change(await screen.findByLabelText('Reason (optional)'), { target: { value: 'Moving' } })
    fireEvent.click(screen.getAllByRole('button', { name: 'Decline' }).at(-1))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/renewals/1/decline', { reason: 'Moving' }))
    fireEvent.click(screen.getByRole('tab', { name: /Declined/ }))
    fireEvent.click(screen.getByRole('button', { name: 'Reopen' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/renewals/2/reopen'))
  })

  it('writes the renewal notification', () => {
    expect(notificationText(i18n.t.bind(i18n), 'en', { kind: 'student_renewed', params: { student_ar: 'علي', student_en: 'Ali', title: '2027/2028' } })).toBe('Ali is registered for 2027/2028')
  })
})
