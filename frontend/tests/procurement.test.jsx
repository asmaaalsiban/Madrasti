import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import ProcurementPage from '../src/features/Procurement/ProcurementPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
const wrap = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter><ProcurementPage /></MemoryRouter></QueryClientProvider>)

const req = (id, status, extra = {}) => ({ id, title: `Item ${id}`, description: null, quantity: 2, category: 'stationery', priority: 'normal', status, estimated_cost: '35.00', needed_by: null, created_at: '2026-11-01T10:00:00Z', requester: { id: 5, name: 'Sara Ali' }, review_note: null, reviewed_by: null, supplier: null, actual_cost: null, purchase_ref: null, purchased_at: null, received_at: null, mine: false, ...extra })
const board = (rows, flags = {}) => ({ data: { data: rows, currency: 'JOD', can_review: false, can_buy: false, summary: { pending: rows.filter((r) => r.status === 'pending').length, approved: 0, purchased: 0, received: 0, rejected: 0, to_spend: '0.00', spent: '0.00' }, ...flags } })

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
})

describe('ProcurementPage', () => {
  it('a member of staff asks for something and sees only their card actions', async () => {
    api.get.mockResolvedValue(board([req(1, 'pending', { mine: true })]))
    api.post.mockResolvedValue({ data: { data: {} } })
    wrap()
    expect(await screen.findByText('Item 1')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument()
    expect(screen.queryByText('Waiting for a decision')).not.toBeInTheDocument() // no totals for them
    fireEvent.click(screen.getByRole('button', { name: 'New request' }))
    fireEvent.change(await screen.findByLabelText('What do you need?'), { target: { value: 'Markers' } })
    fireEvent.change(screen.getByLabelText('Quantity'), { target: { value: '12' } })
    fireEvent.change(screen.getByLabelText('Priority'), { target: { value: 'urgent' } })
    fireEvent.change(screen.getByLabelText('Estimated cost'), { target: { value: '20' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/procurement', expect.objectContaining({ title: 'Markers', quantity: '12', priority: 'urgent', estimated_cost: '20', needed_by: null })))
  })

  it('the principal approves or declines with a reason, and sees the totals', async () => {
    api.get.mockResolvedValue(board([req(1, 'pending'), req(2, 'pending')], { can_review: true }))
    api.post.mockResolvedValue({ data: { data: {} } })
    wrap()
    expect(await screen.findByText('Waiting for a decision')).toBeInTheDocument()
    fireEvent.click(screen.getAllByRole('button', { name: 'Approve' })[0])
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/procurement/1/approve'))
    fireEvent.click(screen.getAllByRole('button', { name: 'Decline' })[1])
    const dialog = await screen.findByRole('dialog', { name: 'Decline: Item 2' })
    fireEvent.change(within(dialog).getByLabelText('Reason (the requester will read it)'), { target: { value: 'No budget' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Decline' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/procurement/2/reject', { note: 'No budget' }))
  })

  it('the accountant records what was bought', async () => {
    api.get.mockResolvedValue(board([req(3, 'approved')], { can_buy: true }))
    api.post.mockResolvedValue({ data: { data: {} } })
    wrap()
    fireEvent.click(await screen.findByRole('button', { name: 'Record purchase' }))
    const dialog = await screen.findByRole('dialog', { name: 'Purchase: Item 3' })
    fireEvent.change(within(dialog).getByLabelText('Bought from'), { target: { value: 'Al Noor Library' } })
    fireEvent.change(within(dialog).getByLabelText('What it cost (JOD)'), { target: { value: '32.5' } })
    fireEvent.change(within(dialog).getByLabelText('Invoice number'), { target: { value: 'INV-77' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Record purchase' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/procurement/3/purchase', { supplier: 'Al Noor Library', actual_cost: '32.5', purchase_ref: 'INV-77', purchased_on: null, expense_category_id: null, due_date: null }))
  })

  it('can pay the bill right away from an account', async () => {
    api.get.mockImplementation(async (url) => (url === '/finance/expenses'
      ? { data: { categories: [], accounts: [{ id: 7, name: 'Cash box' }], methods: ['cash'] } }
      : board([req(3, 'approved')], { can_buy: true })))
    api.post.mockResolvedValue({ data: { data: {} } })
    wrap()
    fireEvent.click(await screen.findByRole('button', { name: 'Record purchase' }))
    const dialog = await screen.findByRole('dialog', { name: 'Purchase: Item 3' })
    fireEvent.change(within(dialog).getByLabelText('Bought from'), { target: { value: 'Al Noor' } })
    fireEvent.click(within(dialog).getByLabelText('Paid now'))
    fireEvent.change(await within(dialog).findByLabelText('Pay from account'), { target: { value: '7' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Record purchase' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/procurement/3/purchase', expect.objectContaining({ pay_now: { money_account_id: 7, method: 'cash', reference: null } })))
  })

  it('the requester confirms it arrived and sees where it was bought', async () => {
    api.get.mockResolvedValue(board([req(4, 'purchased', { mine: true, supplier: 'Al Noor Library', actual_cost: '32.50', purchase_ref: 'INV-77' })]))
    api.post.mockResolvedValue({ data: { data: {} } })
    wrap()
    expect(await screen.findByText(/Bought from Al Noor Library/)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'It arrived' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/procurement/4/receive'))
  })

  it('deletes a pending request of mine only after the styled confirmation', async () => {
    api.get.mockResolvedValue(board([req(1, 'pending', { mine: true })]))
    api.delete.mockResolvedValue({ data: {} })
    wrap()
    fireEvent.click(await screen.findByRole('button', { name: 'Delete' }))
    const ask = await screen.findByRole('dialog', { name: 'Delete this request?' })
    expect(ask).toHaveTextContent('Item 1')
    expect(api.delete).not.toHaveBeenCalled()
    fireEvent.click(within(ask).getByRole('button', { name: 'Delete' }))
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/procurement/1'))
  })

  it('shows a declined column only when something was declined, with the reason', async () => {
    api.get.mockResolvedValue({ data: { ...board([req(5, 'rejected', { review_note: 'Too expensive', reviewed_by: 'Principal' })]).data, summary: { pending: 0, approved: 0, purchased: 0, received: 0, rejected: 1, to_spend: '0.00', spent: '0.00' } } })
    wrap()
    expect(await screen.findByText('“Too expensive”')).toBeInTheDocument()
    expect(screen.getAllByText('Declined').length).toBeGreaterThan(0)
  })
})
