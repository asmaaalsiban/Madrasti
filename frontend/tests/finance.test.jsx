import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import FinanceDashboardPage from '../src/features/Finance/pages/FinanceDashboardPage'
import FeeStructuresPage from '../src/features/Finance/pages/FeeStructuresPage'
import AccountsPage from '../src/features/Finance/pages/AccountsPage'
import StudentAccountPage from '../src/features/Finance/pages/StudentAccountPage'
import ChildFeesPage from '../src/features/Finance/pages/ChildFeesPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: { children: [{ id: 7, name_en: 'Lina', name_ar: 'لينا' }] } }) }))
vi.mock('../src/tenant/useSchool', () => ({ useSchool: () => ({ data: { name: 'Bright School' } }) }))

const wrap = (ui, path = '/', route = '/') => render(
  <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter initialEntries={[path]}><Routes><Route path={route} element={ui} /></Routes></MemoryRouter></QueryClientProvider>,
)

const statement = {
  currency: 'JOD', payer_default: 'Abu Layla', has_schedule: true, student: { id: 7, name_ar: 'ليلى', name_en: 'Layla', student_number: 'S-1' },
  totals: { net: '1000.00', paid: '250.00', remaining: '750.00', overdue: '250.00' },
  fees: [{
    id: 3, kind: 'yearly', description: 'Tuition', total: '1200.00', discount: '200.00', net: '1000.00', paid: '250.00', remaining: '750.00', overdue: '250.00',
    items: [{ id: 1, kind: 'yearly', description: 'Tuition', amount: '1100.00' }, { id: 2, kind: 'transport', description: 'Transport', amount: '100.00' }, { id: 3, kind: 'discount', description: 'Discount', amount: '-200.00' }],
    installments: [
      { id: 11, number: 1, due_date: '2026-09-01', amount: '500.00', paid: '250.00', remaining: '250.00', status: 'overdue' },
      { id: 12, number: 2, due_date: '2026-12-01', amount: '500.00', paid: '0.00', remaining: '500.00', status: 'pending' },
    ],
    payments: [{ id: 21, receipt: 'R-000021', amount: '250.00', method: 'cash', reference: null, note: null, paid_on: '2026-09-05', received_by: 'Accountant', payer_name: 'Abu Layla' }],
  }],
}

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
  window.print = vi.fn()
})

describe('FinanceDashboardPage', () => {
  const serve = (canManage) => api.get.mockImplementation((url) => Promise.resolve({ data: { data: url === '/finance/summary'
    ? { expected: '2000.00', collected: '600.00', remaining: '1400.00', overdue: '500.00', collected_this_month: '600.00', students_overdue: 1, currency: 'JOD' }
    : { currency: 'JOD', can_manage: canManage } } }))

  it('shows the totals and lets the accountant change the currency', async () => {
    serve(true)
    api.put.mockResolvedValue({ data: { data: { currency: 'ILS' } } })
    wrap(<FinanceDashboardPage base="/teacher" />)
    expect(await screen.findByText('2,000.00 JOD')).toBeInTheDocument()
    expect(screen.getByText('Students with overdue installments').nextSibling).toHaveTextContent('1')
    expect(screen.getByRole('link', { name: "Students' accounts" })).toHaveAttribute('href', '/teacher/finance/accounts')
    fireEvent.change(screen.getByLabelText(/Currency/), { target: { value: 'ILS' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/finance/settings', { currency: 'ILS' }))
  })

  it('hides the currency form from the admin', async () => {
    serve(false)
    wrap(<FinanceDashboardPage base="/admin" />)
    await screen.findByText('2,000.00 JOD')
    expect(screen.queryByLabelText(/Currency/)).not.toBeInTheDocument()
  })
})

describe('FeeStructuresPage', () => {
  const serve = (canManage) => api.get.mockImplementation((url) => {
    if (url === '/finance/fee-structures') return Promise.resolve({ data: { currency: 'JOD', data: [{ id: 1, name: 'Tuition', amount: '1200.00', grade_level: { id: 5, name_ar: 'الخامس', name_en: 'Grade 5' }, students: 3, installments_count: 4, first_due: '2026-09-01', every_months: 1, auto_apply: true }] } })
    if (url === '/finance/options') return Promise.resolve({ data: { data: { grades: [{ id: 5, name_ar: 'الخامس', name_en: 'Grade 5' }], classrooms: [] } } })
    return Promise.resolve({ data: { data: { currency: 'JOD', can_manage: canManage } } })
  })

  it('adds a yearly fee', async () => {
    serve(true)
    api.post.mockResolvedValue({ data: { data: { id: 2 } } })
    wrap(<FeeStructuresPage />)
    expect(await screen.findByText('Tuition · Grade 5')).toBeInTheDocument()
    expect(screen.getByText(/given to 3 students/)).toBeInTheDocument()
    expect(screen.getByText(/4 installments from 2026-09-01 · automatic/)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'New yearly fee' }))
    fireEvent.change(await screen.findByLabelText('Name'), { target: { value: 'Bus' } })
    fireEvent.change(screen.getByLabelText('Amount'), { target: { value: '300' } })
    fireEvent.change(screen.getByLabelText('First installment due on'), { target: { value: '2026-09-01' } })
    fireEvent.change(screen.getByLabelText('Number of installments'), { target: { value: '3' } })
    fireEvent.click(screen.getByLabelText('Apply automatically to new students of this grade'))
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/finance/fee-structures', { grade_level_id: 5, name: 'Bus', amount: '300', installments_count: 3, first_due: '2026-09-01', every_months: 1, auto_apply: false }))
  })

  it('gives a fee to the grade, split into installments', async () => {
    serve(true)
    api.post.mockResolvedValue({ data: { data: { students: 12 } } })
    wrap(<FeeStructuresPage />)
    fireEvent.click(await screen.findByRole('button', { name: "Give to the grade's students" }))
    expect(screen.getByLabelText('First installment due on')).toHaveValue('2026-09-01') // the fee's own plan
    fireEvent.click(screen.getByRole('button', { name: 'Give' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/finance/fee-structures/1/apply', { installments: 4, first_due: '2026-09-01', every_months: 1, discount: 0 }))
    expect(await screen.findByRole('status')).toHaveTextContent('given to 12 students')
  })

  it('is read-only for the admin', async () => {
    serve(false)
    wrap(<FeeStructuresPage />)
    await screen.findByText('Tuition · Grade 5')
    expect(screen.queryByRole('button', { name: 'New yearly fee' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument()
  })
})

describe('AccountsPage', () => {
  it('lists balances with filters and links to the statement', async () => {
    api.get.mockImplementation((url) => Promise.resolve(url === '/finance/options'
      ? { data: { data: { grades: [{ id: 5, name_ar: 'الخامس', name_en: 'Grade 5' }], classrooms: [{ id: 3, name: '5-A', grade_level_id: 5 }] } } }
      : { data: { currency: 'JOD', last_page: 1, data: [{ id: 7, name_ar: 'ليلى', name_en: 'Layla', student_number: 'S-1', classroom: '5-A', net: '1000.00', paid: '250.00', remaining: '750.00', overdue: '250.00' }] } }))
    wrap(<AccountsPage base="/teacher" />)
    const link = await screen.findByRole('link', { name: 'Layla' })
    expect(link).toHaveAttribute('href', '/teacher/finance/students/7')
    expect(screen.getByText('750.00 JOD')).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Status'), { target: { value: 'overdue' } })
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/finance/accounts', { params: { status: 'overdue', page: 1 } }))
  })
})

describe('StudentAccountPage', () => {
  const serve = (canManage) => api.get.mockImplementation((url) => Promise.resolve({ data: { data: url.startsWith('/finance/students/') ? statement : { currency: 'JOD', can_manage: canManage } } }))
  const open = () => wrap(<StudentAccountPage />, '/s/7', '/s/:id')

  it('shows the statement and records a payment, then offers the receipt', async () => {
    serve(true)
    api.post.mockResolvedValue({ data: { data: { id: 22, receipt: 'R-000022', amount: '250.00', method: 'cash', reference: null, paid_on: '2026-10-24', received_by: 'Accountant' } } })
    open()
    expect(await screen.findByText('Layla')).toBeInTheDocument()
    expect(screen.getAllByText('1,000.00 JOD').length).toBeGreaterThan(0)
    expect(screen.getByText('Overdue', { selector: 'span' })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Record a payment' }))
    fireEvent.change(await screen.findByLabelText('Amount'), { target: { value: '250' } })
    fireEvent.change(screen.getByLabelText('Reference (cheque / transfer number)'), { target: { value: 'CH-9' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save the payment' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/finance/payments', expect.objectContaining({ student_fee_id: 3, amount: '250', method: 'cash', reference: 'CH-9', installment_id: null })))
    const receipt = await screen.findByRole('dialog', { name: 'Receipt' })
    expect(within(receipt).getByText('Bright School')).toBeInTheDocument()
    expect(within(receipt).getByText('R-000022')).toBeInTheDocument()
    fireEvent.click(within(receipt).getByRole('button', { name: 'Print' }))
    expect(window.print).toHaveBeenCalled()
  })

  it('shows the reason when a payment is refused', async () => {
    serve(true)
    api.post.mockRejectedValue({ response: { data: { errors: { amount: ['The payment is more than what is still owed (750.00).'] } } } })
    open()
    fireEvent.click(await screen.findByRole('button', { name: 'Record a payment' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Save the payment' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('more than what is still owed')
  })

  it('cancels a payment after confirmation', async () => {
    serve(true)
    api.delete.mockResolvedValue({})
    open()
    fireEvent.click(await screen.findByRole('button', { name: 'Cancel payment' }))
    fireEvent.click(screen.getAllByRole('button', { name: 'Cancel payment' }).at(-1))
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/finance/payments/21'))
  })

  it('shows what the fee is made of and who paid', async () => {
    serve(true)
    open()
    const items = await screen.findByRole('list', { name: 'What the fee is made of' })
    expect(within(items).getByText('Transport')).toBeInTheDocument()
    expect(within(items).getByText('discount')).toBeInTheDocument()
    expect(screen.getByText(/paid by Abu Layla/)).toBeInTheDocument()
  })

  it('adds fees to a student that already has a schedule: spread over the open installments, no schedule of its own', async () => {
    serve(true)
    api.post.mockResolvedValue({ data: { data: { id: 3, spread: true } } })
    open()
    fireEvent.click(await screen.findByRole('button', { name: 'Add fees' }))
    expect(await screen.findByText(/spread over the 2 installments still open/)).toBeInTheDocument()
    expect(screen.queryByLabelText('First installment due on')).not.toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Type'), { target: { value: 'transport' } })
    expect(screen.getByLabelText('Description')).toHaveValue('Transport')
    fireEvent.change(screen.getByLabelText('Amount to add'), { target: { value: '100' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/finance/students/7/fees', { description: 'Transport', total_amount: '100', kind: 'transport' }))
  })

  it('creates the whole plan at once for a student with no schedule: fee, transport, discount, installments and first payment', async () => {
    api.get.mockImplementation((url) => Promise.resolve({ data: { data: url.startsWith('/finance/students/') ? { ...statement, has_schedule: false, fees: [] } : { currency: 'JOD', can_manage: true } } }))
    api.post.mockResolvedValue({ data: { data: { id: 9, spread: false, payments: [{ id: 5, receipt: 'R-000005', amount: '200.00', method: 'cash', reference: null, payer_name: 'Abu Layla', paid_on: '2026-10-25', received_by: 'Accountant' }] } } })
    open()
    fireEvent.click(await screen.findByRole('button', { name: 'Add a fee' }))
    fireEvent.change(await screen.findByLabelText('Description'), { target: { value: 'Tuition' } })
    fireEvent.change(screen.getByLabelText('Total'), { target: { value: '900' } })
    fireEvent.change(screen.getByLabelText('Transport fee (optional, inside the same installments)'), { target: { value: '100' } })
    fireEvent.change(screen.getByLabelText('Discount'), { target: { value: '100' } })
    fireEvent.change(screen.getByLabelText('First installment due on'), { target: { value: '2026-09-01' } })
    fireEvent.change(screen.getByLabelText('First payment amount'), { target: { value: '200' } })
    expect(screen.getByLabelText('Paid by (the receipt is written in this name)')).toHaveValue('Abu Layla') // starts with the parent's name
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/finance/students/7/fees', { description: 'Tuition', total_amount: '900', discount: '100', transport_fee: '100', installments_count: 9, first_due: '2026-09-01', every_months: 1, first_payment: '200', first_payment_method: 'cash', payer_name: 'Abu Layla' }))
    const receipt = await screen.findByRole('dialog', { name: 'Receipt' })
    expect(within(receipt).getByText('R-000005')).toBeInTheDocument()
  })

  it('changes the transport line from the account', async () => {
    serve(true)
    api.put.mockResolvedValue({ data: { data: {} } })
    open()
    fireEvent.click(await screen.findByRole('button', { name: 'Change the transport fee' }))
    expect(await screen.findByLabelText('Transport fee (total for the year)')).toHaveValue(100)
    fireEvent.change(screen.getByLabelText('Transport fee (total for the year)'), { target: { value: '160' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/finance/students/7/transport', { amount: '160' }))
  })

  it('changes the discount at any time, even after payments', async () => {
    serve(true)
    api.put.mockResolvedValue({ data: { data: {} } })
    open()
    fireEvent.click(await screen.findByRole('button', { name: 'Change the discount' }))
    fireEvent.change(await screen.findByLabelText('Discount'), { target: { value: '300' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/finance/fees/3', { discount: '300' }))
  })

  it('records a payment in the name of who paid', async () => {
    serve(true)
    api.post.mockResolvedValue({ data: { data: { id: 22, receipt: 'R-000022', amount: '250.00', method: 'cash', reference: null, payer_name: 'Um Layla', paid_on: '2026-10-25', received_by: 'Accountant' } } })
    open()
    fireEvent.click(await screen.findByRole('button', { name: 'Record a payment' }))
    const name = await screen.findByLabelText('Paid by (the receipt is written in this name)')
    expect(name).toHaveValue('Abu Layla')
    fireEvent.change(name, { target: { value: 'Um Layla' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save the payment' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/finance/payments', expect.objectContaining({ payer_name: 'Um Layla' })))
    const receipt = await screen.findByRole('dialog', { name: 'Receipt' })
    expect(within(receipt).getByText('Um Layla')).toBeInTheDocument()
  })

  it('is read-only for the admin', async () => {
    serve(false)
    open()
    await screen.findByText('Layla')
    for (const name of ['Record a payment', 'Add a fee', 'Add a transport fee', 'Cancel payment', 'Receipt']) expect(screen.queryByRole('button', { name })).not.toBeInTheDocument()
  })
})

describe('ChildFeesPage', () => {
  it("shows a parent the child's statement without any buttons", async () => {
    api.get.mockResolvedValue({ data: { data: statement } })
    wrap(<ChildFeesPage />, '/c/7', '/c/:id')
    expect((await screen.findAllByText('Tuition')).length).toBeGreaterThan(0)
    expect(api.get).toHaveBeenCalledWith('/students/7/fees')
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Lina')
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
  })
})
