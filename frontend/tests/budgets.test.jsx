import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import BudgetsPage from '../src/features/Finance/pages/BudgetsPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), put: vi.fn() } }))
const wrap = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter><BudgetsPage /></MemoryRouter></QueryClientProvider>)
const board = { data: { data: { year: { id: 1, name: '2026/2027' }, totals: { budget: '1000.00', spent: '1200.00', remaining: '-200.00' }, rows: [
  { category_id: 5, name_ar: 'إيجار', name_en: 'Rent', budget: '1000.00', spent: '1200.00', remaining: '-200.00', percent: 120, status: 'over' }] }, years: [{ id: 1, name: '2026/2027' }], can_manage: true } }

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
  api.get.mockResolvedValue(board)
  api.put.mockResolvedValue({ data: { saved: 1 } })
})

describe('BudgetsPage', () => {
  it('flags a category that is over budget and saves a changed budget', async () => {
    wrap()
    expect(await screen.findByText('Over budget')).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Budget Rent'), { target: { value: '1500' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/finance/budgets', { academic_year_id: 1, items: [{ category_id: 5, amount: '1500' }] }))
  })

  it('is read only for someone who cannot manage', async () => {
    api.get.mockResolvedValue({ data: { ...board.data, can_manage: false } })
    wrap()
    expect(await screen.findByLabelText('Budget Rent')).toBeDisabled()
    expect(screen.queryByRole('button', { name: 'Save' })).toBeNull()
  })
})
