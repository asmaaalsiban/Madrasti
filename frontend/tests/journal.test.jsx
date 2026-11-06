import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import JournalPage from '../src/features/Finance/pages/JournalPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))

const accounts = { data: { data: [{ id: 1, code: '1000', name_ar: 'النقدية', name_en: 'Cash', type: 'asset', is_active: true }, { id: 2, code: '4100', name_ar: 'إيرادات أخرى', name_en: 'Other income', type: 'income', is_active: true }], types: ['asset', 'income'], can_manage: true } }
const journal = { data: { data: [{ id: 9, date: '2026-11-05', memo: 'Donation', manual: true, total: '100.00', lines: [{ code: '1000', name_en: 'Cash', name_ar: 'x', debit: '100.00', credit: '0.00' }, { code: '4100', name_en: 'Other income', name_ar: 'y', debit: '0.00', credit: '100.00' }] }], locked_until: null, can_manage: true } }

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
  api.get.mockImplementation(async (url) => (url === '/finance/journal' ? journal : accounts))
  api.post.mockResolvedValue({ data: { data: { id: 1 } } })
  api.put.mockResolvedValue({ data: {} })
})

const wrap = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter><JournalPage /></MemoryRouter></QueryClientProvider>)

describe('JournalPage', () => {
  it('lists entries and only saves a balanced one', async () => {
    wrap()
    expect(await screen.findByText(/Donation/)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'New entry' }))
    const dialog = await screen.findByRole('dialog', { name: 'New entry' })
    fireEvent.change(within(dialog).getByLabelText('Description'), { target: { value: 'Gift' } })
    const selects = within(dialog).getAllByLabelText('Account')
    fireEvent.change(selects[0], { target: { value: '1' } })
    fireEvent.change(selects[1], { target: { value: '2' } })
    fireEvent.change(within(dialog).getAllByLabelText('Debit')[0], { target: { value: '50' } })
    expect(within(dialog).getByRole('button', { name: 'Save' })).toBeDisabled()
    fireEvent.change(within(dialog).getAllByLabelText('Credit')[1], { target: { value: '50' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/finance/journal', expect.objectContaining({ memo: 'Gift', lines: [{ ledger_account_id: 1, debit: '50', credit: null }, { ledger_account_id: 2, debit: null, credit: '50' }] })))
  })

  it('locks the books up to a date', async () => {
    wrap()
    await screen.findByText(/Donation/)
    fireEvent.change(screen.getByLabelText('Books locked until'), { target: { value: '2026-10-31' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/finance/books-lock', { locked_until: '2026-10-31' }))
  })
})
