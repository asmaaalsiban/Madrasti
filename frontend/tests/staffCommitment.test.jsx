import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import CheckInCard from '../src/features/StaffAttendance/CheckInCard'
import StaffAttendancePage from '../src/features/StaffAttendance/StaffAttendancePage'
import RulesPage from '../src/features/Payroll/pages/RulesPage'
import CommitmentCard from '../src/features/Payroll/components/CommitmentCard'
import { itemLabel } from '../src/features/Payroll/itemLabel'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
const auth = { user: { role: 'admin' } }
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: auth.user }) }))
const wrap = (ui) => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter>{ui}</MemoryRouter></QueryClientProvider>)

beforeEach(async () => {
  vi.resetAllMocks()
  auth.user = { role: 'admin' }
  await i18n.changeLanguage('en')
})

describe('CheckInCard', () => {
  it('lets a person check in and then out', async () => {
    api.get.mockResolvedValue({ data: { data: { workday: true, today: null, starts_at: '07:45' } } })
    api.post.mockResolvedValue({ data: {} })
    wrap(<CheckInCard />)
    expect(await screen.findByText('You have not checked in yet. The day starts at 07:45.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'I arrived' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/staff-attendance/check-in'))
  })

  it('offers check-out once arrived and nothing on a day off', async () => {
    api.get.mockResolvedValue({ data: { data: { workday: true, today: { status: 'late', check_in: '08:10', check_out: null }, starts_at: '07:45' } } })
    const { unmount } = wrap(<CheckInCard />)
    expect(await screen.findByRole('button', { name: "I'm leaving" })).toBeInTheDocument()
    expect(screen.getByText(/Late · arrived 08:10/)).toBeInTheDocument()
    unmount()
    api.get.mockResolvedValue({ data: { data: { workday: false, today: null, starts_at: '07:45' } } })
    wrap(<CheckInCard />)
    await waitFor(() => expect(api.get).toHaveBeenCalledTimes(2))
    expect(screen.queryByRole('button', { name: 'I arrived' })).not.toBeInTheDocument()
  })
})

describe('StaffAttendancePage', () => {
  const day = { date: '2026-11-10', workday: true, data: [{ user: { id: 1, name: 'Sara' }, status: 'late', check_in: '08:20', check_out: null, note: null }, { user: { id: 2, name: 'Omar' }, status: null, check_in: null, check_out: null, note: null }] }
  beforeEach(() => api.get.mockImplementation((url) => Promise.resolve({ data: url === '/staff-attendance/me' ? { data: { workday: false, today: null } } : day })))

  it('shows the day and lets the principal mark an absence', async () => {
    api.put.mockResolvedValue({ data: {} })
    wrap(<StaffAttendancePage />)
    expect(await screen.findByText('Sara')).toBeInTheDocument()
    fireEvent.click(screen.getAllByRole('button', { name: 'Edit' })[1])
    fireEvent.change(await screen.findByLabelText('Status'), { target: { value: 'absent' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/staff-attendance/2', expect.objectContaining({ status: 'absent', check_in: null })))
  })

  it('is read only for the accountant', async () => {
    auth.user = { role: 'teacher', staff_type: 'accountant' }
    wrap(<StaffAttendancePage />)
    await screen.findByText('Sara')
    expect(screen.queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument()
  })
})

describe('RulesPage', () => {
  const rule = { enabled: false, bonus_min: 90, bonus_type: 'amount', bonus_value: 0, deduction_tiers: [] }
  const rules = { grace_minutes: 10, punctuality: rule, daily_summary: rule, weekly_plan: rule, absence: { enabled: false, type: 'amount', value: 0 } }

  it('saves the rules the principal sets', async () => {
    api.get.mockResolvedValue({ data: { data: rules } })
    api.put.mockResolvedValue({ data: {} })
    wrap(<RulesPage base="/admin" />)
    fireEvent.click((await screen.findAllByLabelText('Apply this rule'))[0])
    fireEvent.change(screen.getByLabelText('Punctuality (arriving on time) Bonus'), { target: { value: '20' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/payroll/rules', expect.objectContaining({ punctuality: expect.objectContaining({ enabled: true, bonus_value: '20' }) })))
  })

  it('adds a deduction step', async () => {
    api.get.mockResolvedValue({ data: { data: rules } })
    api.put.mockResolvedValue({ data: {} })
    wrap(<RulesPage base="/admin" />)
    fireEvent.click((await screen.findAllByRole('button', { name: '+ Add a step' }))[0])
    fireEvent.change(screen.getByLabelText('Punctuality (arriving on time) Deduction 1'), { target: { value: '25' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/payroll/rules', expect.objectContaining({ punctuality: expect.objectContaining({ deduction_tiers: [{ below: 60, type: 'amount', value: '25' }] }) })))
  })

  it('saves steps and the absence rule', async () => {
    api.get.mockResolvedValue({ data: { data: { ...rules, punctuality: { ...rule, enabled: true, deduction_tiers: [{ below: 80, type: 'amount', value: 10 }] } } } })
    api.put.mockResolvedValue({ data: {} })
    wrap(<RulesPage base="/admin" />)
    fireEvent.change(await screen.findByLabelText('Punctuality (arriving on time) Deduction 1'), { target: { value: '15' } })
    fireEvent.click(screen.getAllByRole('checkbox').at(-1))
    fireEvent.change(screen.getByLabelText('Absence'), { target: { value: '12' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/payroll/rules', expect.objectContaining({ punctuality: expect.objectContaining({ deduction_tiers: [{ below: 80, type: 'amount', value: '15' }] }), absence: { enabled: true, type: 'amount', value: '12' } })))
  })

  it('is read only for the accountant', async () => {
    auth.user = { role: 'teacher', staff_type: 'accountant' }
    api.get.mockResolvedValue({ data: { data: rules } })
    wrap(<RulesPage base="/teacher" />)
    await screen.findAllByLabelText('Apply this rule')
    expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument()
  })
})

describe('CommitmentCard and labels', () => {
  it('shows the scores and what they would add or take off', async () => {
    api.get.mockResolvedValue({ data: { scores: { punctuality: { score: 96, done: 24, expected: 25 }, daily_summary: { score: 40, done: 4, expected: 10 }, weekly_plan: { score: null, done: 0, expected: 0 } }, lines: [{ code: 'punctuality', kind: 'bonus', amount: '20.00', score: 96 }, { code: 'daily_summary', kind: 'deduction', amount: '30.00', score: 40 }] } })
    wrap(<CommitmentCard currency="JOD" />)
    expect(await screen.findByText('96%')).toBeInTheDocument()
    expect(screen.getByText(/Punctuality bonus \(96%\)/)).toBeInTheDocument()
    expect(screen.getByText(/Missing daily summaries \(40%\)/)).toBeInTheDocument()
    expect(screen.queryByText('Weekly lesson plans')).not.toBeInTheDocument() // does not apply
  })

  it('writes automatic lines in the reader language', async () => {
    const item = { kind: 'bonus', label: 'x', auto_code: 'punctuality', detail: '96' }
    expect(itemLabel(i18n.t.bind(i18n), item)).toBe('Punctuality bonus (96%)')
    await i18n.changeLanguage('ar')
    expect(itemLabel(i18n.t.bind(i18n), item)).toBe('مكافأة الانضباط (96%)')
    expect(itemLabel(i18n.t.bind(i18n), { kind: 'deduction', label: 'Loan' })).toBe('Loan')
  })
})
