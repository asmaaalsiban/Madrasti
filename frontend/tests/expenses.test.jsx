import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import ExpensesPage from '../src/features/Finance/pages/ExpensesPage'
import SuppliersPage from '../src/features/Finance/pages/SuppliersPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))

const util = { id: 3, name_ar: 'كهرباء', name_en: 'Utilities' }
const bills = (extra = {}) => ({
  data: {
    data: [
      { id: 1, date: '2026-11-05', supplier: { id: 1, name: 'Power Co' }, category: util, description: 'Electricity October', reference: 'INV-9', amount: '400.00', due_date: '2026-11-01', procurement_request_id: null, paid: '150.00', remaining: '250.00', status: 'overdue', payments: [{ id: 7, amount: '150.00', paid_on: '2026-11-06', method: 'cash', account: { id: 1, name: 'Cash box' }, reference: null, note: null }] },
      { id: 2, date: '2026-11-07', supplier: null, category: util, description: 'Water', reference: null, amount: '90.00', due_date: null, procurement_request_id: null, paid: '90.00', remaining: '0.00', status: 'paid', payments: [] },
    ],
    summary: { total: '490.00', paid: '240.00', remaining: '250.00', overdue: '250.00', by_category: [{ category: util, total: '490.00' }] },
    categories: [util], suppliers: [{ id: 1, name: 'Power Co' }], accounts: [{ id: 1, name: 'Cash box', type: 'cash' }, { id: 2, name: 'Arab Bank', type: 'bank' }], methods: ['cash', 'bank_transfer', 'cheque', 'card'], currency: 'JOD', can_manage: true, ...extra,
  },
})
const wrap = (ui) => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter>{ui}</MemoryRouter></QueryClientProvider>)
const serve = (b = bills()) => api.get.mockImplementation((url) => Promise.resolve(url === '/procurement' ? { data: { data: [{ id: 5, title: 'Markers', status: 'approved', estimated_cost: '40.00' }, { id: 6, title: 'Chairs', status: 'pending' }] } } : url === '/finance/expense-categories' ? { data: { data: [{ ...util, is_active: true, bills: 2 }] } } : b))

beforeEach(async () => { vi.resetAllMocks(); await i18n.changeLanguage('en') })

describe('ExpensesPage', () => {
  it('shows the bills with their status, the totals and the breakdown by category', async () => {
    serve()
    wrap(<ExpensesPage base="/teacher" />)
    expect(await screen.findByText('Electricity October')).toBeInTheDocument()
    expect(screen.getByText('Overdue', { selector: 'span' })).toBeInTheDocument()
    expect(screen.getByText('Total bills')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Suppliers' })).toHaveAttribute('href', '/teacher/finance/suppliers')
    expect(within(screen.getByRole('list', { name: 'By category' })).getByText(/Utilities/)).toBeInTheDocument()
  })

  it('records a bill already paid from an account, and an approved purchase request can fill it in', async () => {
    serve()
    api.post.mockResolvedValue({ data: { data: {} } })
    wrap(<ExpensesPage base="/teacher" />)
    fireEvent.click(await screen.findByRole('button', { name: 'Add a bill' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(await within(dialog).findByLabelText('From an approved purchase request (optional)'), { target: { value: '5' } })
    expect(within(dialog).getByLabelText('Description')).toHaveValue('Markers') // filled from the request
    fireEvent.change(within(dialog).getByLabelText('Category'), { target: { value: '3' } })
    fireEvent.click(within(dialog).getByRole('checkbox', { name: 'Paid now' }))
    fireEvent.change(within(dialog).getByLabelText('From account'), { target: { value: '2' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(api.post).toHaveBeenCalled())
    const [url, body] = api.post.mock.calls[0]
    expect(url).toBe('/finance/expenses')
    expect(body).toMatchObject({ description: 'Markers', amount: '40.00', expense_category_id: 3, procurement_request_id: 5 })
    expect(body.pay_now).toMatchObject({ money_account_id: 2, method: 'cash' })
  })

  it('pays what is left on a bill from the detail, and can cancel a payment after a confirmation', async () => {
    serve()
    api.post.mockResolvedValue({ data: { data: {} } })
    api.delete.mockResolvedValue({ data: { data: {} } })
    wrap(<ExpensesPage base="/teacher" />)
    fireEvent.click((await screen.findAllByRole('button', { name: 'Open' }))[0])
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('From account'), { target: { value: '2' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Pay' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/finance/expenses/1/payments', expect.objectContaining({ amount: '250.00', money_account_id: 2 }))) // the whole remaining by default

    fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel the payment' }))
    fireEvent.click((await screen.findAllByRole('button', { name: 'Cancel the payment' })).pop())
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/finance/expense-payments/7'))
  })

  it('is read only for the principal', async () => {
    serve(bills({ can_manage: false }))
    wrap(<ExpensesPage base="/admin" />)
    await screen.findByText('Electricity October')
    expect(screen.queryByRole('button', { name: 'Add a bill' })).not.toBeInTheDocument()
  })

  it('shows the server message when a payment is refused', async () => {
    serve()
    api.post.mockRejectedValue({ response: { data: { errors: { amount: ['The payment is more than what is still owed on this bill (250.00).'] } } } })
    wrap(<ExpensesPage base="/teacher" />)
    fireEvent.click((await screen.findAllByRole('button', { name: 'Open' }))[0])
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('From account'), { target: { value: '1' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Pay' }))
    expect(await within(dialog).findByRole('alert')).toHaveTextContent('more than what is still owed')
  })
})

describe('SuppliersPage', () => {
  it('lists the suppliers with what the school owes each and adds one', async () => {
    api.get.mockResolvedValue({ data: { data: [{ id: 1, name: 'Power Co', phone: '0790000000', email: null, notes: null, is_active: true, bills: 3, owed: '250.00', bought: '1200.00' }], currency: 'JOD', can_manage: true } })
    api.post.mockResolvedValue({ data: { data: {} } })
    wrap(<SuppliersPage base="/teacher" />)
    expect(await screen.findByText('Power Co')).toBeInTheDocument()
    expect(screen.getByText('250.00 JOD', { exact: false })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Add a supplier' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Supplier name'), { target: { value: 'Stationery Shop' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/finance/suppliers', expect.objectContaining({ name: 'Stationery Shop' })))
  })
})
