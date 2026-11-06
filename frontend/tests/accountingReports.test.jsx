import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import AccountingReportsPage from '../src/features/Finance/pages/AccountingReportsPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn() } }))
const wrap = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter><AccountingReportsPage /></MemoryRouter></QueryClientProvider>)

const trial = { columns: ['code', 'account', 'debit', 'credit'], rows: [['1000', 'Cash', '400.00', '0.00']], totals: { debit: '400.00', credit: '400.00' }, balanced: true }

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
  api.get.mockImplementation(async (url) => (url === '/finance/ledger-accounts' ? { data: { data: [{ id: 1, code: '1000', name_en: 'Cash', name_ar: 'x', is_active: true }] } } : { data: { data: trial } }))
})

describe('AccountingReportsPage', () => {
  it('shows the trial balance with its totals', async () => {
    wrap()
    expect(await screen.findByText('Cash')).toBeInTheDocument()
    expect(screen.getByText('Total debit')).toBeInTheDocument()
  })

  it('asks for an account before showing a ledger and warns when books do not balance', async () => {
    wrap()
    await screen.findByText('Cash')
    fireEvent.click(screen.getByRole('button', { name: 'Account ledger' }))
    expect(await screen.findByText('Pick an account to see its ledger.')).toBeInTheDocument()
    api.get.mockImplementation(async (url) => (url === '/finance/ledger-accounts' ? { data: { data: [] } } : { data: { data: { ...trial, balanced: false } } }))
    fireEvent.click(screen.getByRole('button', { name: 'Trial balance' }))
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('do not balance'))
  })
})
