import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import MoneyAccountsPage from '../src/features/Finance/pages/MoneyAccountsPage'
import MoneyLedgerPage from '../src/features/Finance/pages/MoneyLedgerPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))

const accounts = (canManage = true) => ({
  data: {
    data: [
      { id: 1, name: 'Cash box', type: 'cash', bank_name: null, iban: null, is_active: true, opening_balance: '0.00', balance: '800.00', unmatched: 0 },
      { id: 2, name: 'Arab Bank', type: 'bank', bank_name: 'Arab Bank', iban: 'JO00 TEST', is_active: true, opening_balance: '0.00', balance: '500.00', unmatched: 2 },
    ],
    total: '1300.00', currency: 'JOD', can_manage: canManage,
  },
})
const wrap = (ui, path = '/') => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter initialEntries={[path]}>{ui}</MemoryRouter></QueryClientProvider>)

beforeEach(async () => { vi.resetAllMocks(); await i18n.changeLanguage('en') })

describe('MoneyAccountsPage', () => {
  it('shows each account with its balance and the total, and warns about bank lines not matched', async () => {
    api.get.mockResolvedValue(accounts())
    wrap(<MoneyAccountsPage base="/teacher" />)
    expect(await screen.findByRole('heading', { name: /Cash box/ })).toBeInTheDocument()
    expect(screen.getByText('Arab Bank', { selector: 'h2' })).toBeInTheDocument()
    expect(screen.getByText('2 lines not matched with the bank statement')).toBeInTheDocument()
    expect(screen.getAllByRole('link', { name: 'Account book' })[0]).toHaveAttribute('href', '/teacher/finance/money/1')
  })

  it('moves money from the cash box to the bank', async () => {
    api.get.mockResolvedValue(accounts())
    api.post.mockResolvedValue({ data: { data: {} } })
    wrap(<MoneyAccountsPage base="/teacher" />)
    await screen.findByRole('heading', { name: /Cash box/ })
    fireEvent.click(screen.getAllByRole('button', { name: 'Transfer between accounts' })[1]) // the cash box's own button
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Amount'), { target: { value: '500' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Transfer' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/finance/money-transfers', expect.objectContaining({ from_id: 1, to_id: 2, amount: '500' })))
  })

  it('corrects a balance only with a reason', async () => {
    api.get.mockResolvedValue(accounts())
    api.post.mockResolvedValue({ data: { data: {} } })
    wrap(<MoneyAccountsPage base="/teacher" />)
    await screen.findByRole('heading', { name: /Cash box/ })
    fireEvent.click(screen.getAllByRole('button', { name: 'Correct' })[0])
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Direction'), { target: { value: 'out' } })
    fireEvent.change(within(dialog).getByLabelText('Amount'), { target: { value: '25' } })
    expect(within(dialog).getByLabelText('Reason')).toBeRequired()
    fireEvent.change(within(dialog).getByLabelText('Reason'), { target: { value: 'Bank fee' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/finance/money-accounts/1/adjustments', expect.objectContaining({ direction: 'out', amount: '25', note: 'Bank fee' })))
  })

  it('is read only for the principal', async () => {
    api.get.mockResolvedValue(accounts(false))
    wrap(<MoneyAccountsPage base="/admin" />)
    await screen.findByRole('heading', { name: /Cash box/ })
    expect(screen.queryByRole('button', { name: 'Add an account' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Correct' })).not.toBeInTheDocument()
  })
})

describe('MoneyLedgerPage', () => {
  const ledger = { data: { data: { forward: '1000.00', closing: '1500.00', currency: 'JOD', account: { id: 2, name: 'Arab Bank', balance: '1500.00' }, rows: [
    { id: 1, date: '2026-09-05', kind: 'receipt', description: 'Mum Sara', amount: '300.00', balance: '1300.00', reconciled: true },
    { id: 2, date: '2026-09-20', kind: 'transfer_out', description: 'Transfer to Cash box', amount: '-100.00', balance: '1200.00', reconciled: false },
  ] } } }
  const open = { data: { data: { cleared_balance: '1000.00', currency: 'JOD', last: null, entries: [{ id: 2, date: '2026-09-20', kind: 'receipt', description: 'Mum Omar', amount: '200.00' }, { id: 3, date: '2026-09-25', kind: 'receipt', description: 'Dad Layla', amount: '50.00' }] } } }
  const serve = () => api.get.mockImplementation((url) => Promise.resolve(url.endsWith('/ledger') ? ledger : url.endsWith('/reconciliation') ? open : accounts()))
  const page = () => wrap(<Routes><Route path="/finance/money/:id" element={<MoneyLedgerPage base="/teacher" />} /></Routes>, '/finance/money/2')

  it('shows the balance brought forward, the lines with in and out, and the closing balance', async () => {
    serve()
    page()
    expect(await screen.findByText('Mum Sara', { exact: false })).toBeInTheDocument()
    expect(screen.getByText('Balance brought forward')).toBeInTheDocument()
    expect(screen.getByText('Closing balance')).toBeInTheDocument()
    expect(screen.getByText(/Transfer to Cash box/)).toBeInTheDocument()
  })

  it('finishes the matching only when the difference is zero', async () => {
    serve()
    api.post.mockResolvedValue({ data: { data: {} } })
    page()
    fireEvent.click(await screen.findByRole('tab', { name: 'Match the bank statement' }))
    const finish = await screen.findByRole('button', { name: 'Finish matching' })
    expect(finish).toBeDisabled() // no statement balance yet
    fireEvent.change(screen.getByLabelText('Closing balance on the statement'), { target: { value: '1250' } }) // 1000 + 200 + 50 = 1250
    expect(finish).toBeEnabled()
    fireEvent.click(screen.getByRole('checkbox', { name: /Line 2026-09-25/ })) // leave a line out: now 1200, the statement says 1250
    expect(finish).toBeDisabled()
    fireEvent.click(screen.getByRole('checkbox', { name: /Line 2026-09-25/ }))
    fireEvent.click(finish)
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/finance/money-accounts/2/reconciliation', expect.objectContaining({ statement_balance: '1250', entry_ids: [2, 3] })))
  })
})
