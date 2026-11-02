import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import ContractsPage from '../src/features/Payroll/pages/ContractsPage'
import PayrollRunPage from '../src/features/Payroll/pages/PayrollRunPage'
import MyPayslipsPage from '../src/features/Payroll/pages/MyPayslipsPage'
import TemplatesPage from '../src/features/Payroll/pages/TemplatesPage'
import { notificationText } from '../src/features/Notifications/NotificationBell'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
const auth = { user: { role: 'teacher', staff_type: 'accountant' } }
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: auth.user }) }))

const wrap = (ui, path = '/', route = '/') => render(
  <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter initialEntries={[path]}><Routes><Route path={route} element={ui} /></Routes></MemoryRouter></QueryClientProvider>,
)

beforeEach(async () => {
  vi.resetAllMocks()
  auth.user = { role: 'teacher', staff_type: 'accountant' }
  await i18n.changeLanguage('en')
})

const contract = { id: 5, starts_on: '2025-09-01', ends_on: '2026-08-31', base_salary: '1000.00', status: 'active', days_left: 20, items: [] }

describe('ContractsPage', () => {
  beforeEach(() => {
    api.get.mockResolvedValue({ data: { currency: 'JOD', data: [
      { id: 1, name: 'Sara Ali', staff_type: 'teacher', subject: null, contracts_count: 1, contract },
      { id: 2, name: 'Omar Nasser', staff_type: 'teacher', subject: null, contracts_count: 0, contract: null },
    ] } })
  })

  it('shows who is ending soon and who has no contract', async () => {
    wrap(<ContractsPage base="/teacher" canManage />)
    expect(await screen.findByText('Sara Ali')).toBeInTheDocument()
    expect(screen.getByText('Ends in 20 days')).toBeInTheDocument()
    expect(screen.getAllByText('No contract').length).toBeGreaterThan(0)
  })

  it('previews the new salary and sends the renewal with the raise', async () => {
    api.post.mockResolvedValue({ data: {} })
    wrap(<ContractsPage base="/teacher" canManage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Renew' }))
    fireEvent.change(await screen.findByLabelText('Percent (%)'), { target: { value: '10' } })
    expect(screen.getByText(/1,100\.00 JOD/)).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('New contract ends on'), { target: { value: '2027-08-31' } })
    fireEvent.click(screen.getAllByRole('button', { name: 'Renew' }).at(-1))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/payroll/contracts/5/renew', expect.objectContaining({ raise_type: 'percent', raise_value: '10', ends_on: '2027-08-31' })))
  })

  it('is read only for the principal', async () => {
    wrap(<ContractsPage base="/admin" canManage={false} />)
    await screen.findByText('Sara Ali')
    expect(screen.queryByRole('button', { name: 'Renew' })).not.toBeInTheDocument()
  })
})

describe('PayrollRunPage', () => {
  const run = (status) => ({ data: { currency: 'JOD', data: { id: 9, month: '2026-11', status, return_note: null, approved_by: null, totals: { base: '1000.00', additions: '0.00', deductions: '50.00', net: '950.00' },
    payslips: [{ id: 3, user: { id: 1, name: 'Sara Ali' }, base_salary: '1000.00', additions: '0.00', deductions: '50.00', net: '950.00', paid_on: null, items: [{ id: 7, kind: 'deduction', label: 'Late', amount: '50.00', from_contract: false }] }] } } })

  it('lets the accountant submit a draft', async () => {
    api.get.mockResolvedValue(run('draft'))
    api.post.mockResolvedValue({ data: {} })
    wrap(<PayrollRunPage base="/teacher" />, '/payroll/9', '/payroll/:id')
    fireEvent.click(await screen.findByRole('button', { name: 'Submit for approval' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/payroll/runs/9/submit', undefined))
    expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument()
  })

  it('lets the principal approve or send back, but not edit', async () => {
    auth.user = { role: 'admin' }
    api.get.mockResolvedValue(run('submitted'))
    api.post.mockResolvedValue({ data: {} })
    wrap(<PayrollRunPage base="/admin" />, '/payroll/9', '/payroll/:id')
    fireEvent.click(await screen.findByRole('button', { name: 'Approve' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/payroll/runs/9/approve', undefined))
    expect(screen.getByRole('button', { name: 'Send back' })).toBeInTheDocument()
    expect(screen.queryByText('Bonus / deduction')).not.toBeInTheDocument()
  })

  it('adds a deduction with a reason', async () => {
    api.get.mockResolvedValue(run('draft'))
    api.post.mockResolvedValue({ data: {} })
    wrap(<PayrollRunPage base="/teacher" />, '/payroll/9', '/payroll/:id')
    fireEvent.click(await screen.findByText('Bonus / deduction'))
    fireEvent.change(await screen.findByLabelText('Type'), { target: { value: 'deduction' } })
    fireEvent.change(screen.getByLabelText('Description'), { target: { value: 'Absence' } })
    fireEvent.change(screen.getByLabelText('Amount'), { target: { value: '20' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/payroll/payslips/3/items', { kind: 'deduction', label: 'Absence', amount: '20', category: null }))
  })

  it('changes how one person is paid this month', async () => {
    api.get.mockResolvedValue(run('approved'))
    api.put.mockResolvedValue({ data: {} })
    wrap(<PayrollRunPage base="/teacher" />, '/payroll/9', '/payroll/:id')
    fireEvent.change(await screen.findByLabelText('Payment method Sara Ali'), { target: { value: 'cheque' } })
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/payroll/payslips/3/payment', { payment_method: 'cheque', payment_details: null }))
  })

  it('offers the payment only once approved', async () => {
    api.get.mockResolvedValue(run('approved'))
    wrap(<PayrollRunPage base="/teacher" />, '/payroll/9', '/payroll/:id')
    expect(await screen.findByRole('button', { name: 'Record payment' })).toBeInTheDocument()
  })
})

describe('MyPayslipsPage and notifications', () => {
  const paid = [
    { id: 4, month: '2026-10', net: '950.00', base_salary: '900.00', additions: '80.00', deductions: '30.00', paid_on: '2026-10-30', items: [{ kind: 'bonus', label: 'Maths tutoring', amount: '80.00', category: 'private_lessons' }, { kind: 'deduction', label: 'Late', amount: '30.00', category: null }] },
    { id: 3, month: '2026-09', net: '900.00', base_salary: '900.00', additions: '0.00', deductions: '0.00', paid_on: '2026-09-30', items: [] },
  ]
  it('shows the last paid month with its breakdown, the chart and the earlier months', async () => {
    api.get.mockImplementation((url) => Promise.resolve({ data: { currency: 'JOD', data: url === '/payroll/commitment' ? { scores: {}, lines: [] } : url === '/payroll/my-contract' ? { base_salary: '900.00', payment_method: 'cheque', starts_on: '2026-09-01', ends_on: null, items: [] } : paid } }))
    wrap(<MyPayslipsPage base="/teacher" />)
    const latest = await screen.findByLabelText('Last paid month')
    expect(latest).toHaveTextContent('950.00 JOD')
    expect(latest).toHaveTextContent('Private lessons')
    expect(latest).toHaveTextContent('Maths tutoring')
    expect(latest).toHaveTextContent('−30.00')
    expect(screen.getByLabelText('My salary')).toHaveTextContent('Cheque')
    expect(screen.getByRole('img', { name: 'Net salary by month' })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: /2026-09/ }))
    expect(screen.getAllByRole('link', { name: 'Payslip' }).at(-1)).toHaveAttribute('href', '/teacher/payslips/3')
  })

  it('writes the payroll notifications', () => {
    const text = (kind, params) => notificationText(i18n.t.bind(i18n), 'en', { kind, params })
    expect(text('payroll_submitted', { month: '2026-11' })).toContain('2026-11')
    expect(text('payslip_paid', { month: '2026-11' })).toContain('2026-11')
    expect(text('contract_expiring', { name: 'Sara', date: '2026-12-01' })).toContain('Sara')
  })
})

describe('TemplatesPage and giving a rule', () => {
  const rule = { id: 1, name: 'Summer club', kind: 'bonus', type: 'amount', value: '50.00', category: 'summer_club', is_active: true }

  it('adds a new rule', async () => {
    api.get.mockResolvedValue({ data: { currency: 'JOD', data: [rule] } })
    api.post.mockResolvedValue({ data: {} })
    wrap(<TemplatesPage base="/admin" />)
    expect((await screen.findAllByText('Summer club')).length).toBeGreaterThan(0)
    fireEvent.click(screen.getByRole('button', { name: 'New rule' }))
    fireEvent.change(await screen.findByLabelText('Name'), { target: { value: 'Late penalty' } })
    fireEvent.change(screen.getByLabelText('Type'), { target: { value: 'deduction' } })
    fireEvent.change(screen.getByLabelText('Value'), { target: { value: '5' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/payroll/templates', { name: 'Late penalty', kind: 'deduction', type: 'amount', value: '5', category: null }))
  })

  it('gives a rule to everyone from a draft payroll', async () => {
    const run = { id: 9, month: '2026-11', status: 'draft', return_note: null, approved_by: null, totals: { base: '1', additions: '0', deductions: '0', net: '1' }, payslips: [{ id: 3, user: { id: 1, name: 'Sara Ali' }, base_salary: '1.00', additions: '0.00', deductions: '0.00', net: '1.00', paid_on: null, items: [] }] }
    api.get.mockImplementation((url) => Promise.resolve({ data: url === '/payroll/templates' ? { currency: 'JOD', data: [rule] } : { currency: 'JOD', data: run } }))
    api.post.mockResolvedValue({ data: { applied: 1, skipped: 0 } })
    wrap(<PayrollRunPage base="/teacher" />, '/payroll/9', '/payroll/:id')
    fireEvent.click(await screen.findByRole('button', { name: 'Give a rule to everyone' }))
    await screen.findByRole('option', { name: /Summer club/ })
    fireEvent.change(screen.getByLabelText('Rule'), { target: { value: '1' } })
    fireEvent.click(screen.getByRole('button', { name: 'Apply' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/payroll/runs/9/apply-template', { template_id: 1 }))
    expect(await screen.findByText('Given to 1 person(s); 0 skipped (a deduction would take the net below zero).')).toBeInTheDocument()
  })

  it('assigns staff to a rule for a range of months', async () => {
    api.get.mockImplementation((url) => Promise.resolve({ data: url === '/payroll/staff' ? { currency: 'JOD', data: [{ id: 1, name: 'Sara Ali' }, { id: 2, name: 'Omar Nasser' }] } : url.includes('/assignments') ? { data: [{ id: 8, user: { id: 2, name: 'Omar Nasser' }, starts_on: '2026-07', ends_on: null }] } : { currency: 'JOD', data: [rule] } }))
    api.post.mockResolvedValue({ data: {} })
    wrap(<TemplatesPage base="/admin" />)
    fireEvent.click(await screen.findByRole('button', { name: 'Assign staff' }))
    expect(await screen.findByText('2026-07 → ∞')).toBeInTheDocument() // already assigned
    fireEvent.click(await screen.findByLabelText('Sara Ali'))
    fireEvent.change(screen.getByLabelText('From month'), { target: { value: '2026-07' } })
    fireEvent.change(screen.getByLabelText('To month (optional)'), { target: { value: '2026-08' } })
    fireEvent.click(screen.getByRole('button', { name: 'Assign (1)' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/payroll/templates/1/assignments', { user_ids: [1], starts_on: '2026-07', ends_on: '2026-08' }))
  })
})
