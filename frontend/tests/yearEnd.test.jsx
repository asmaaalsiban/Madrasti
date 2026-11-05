import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import YearEndPage from '../src/features/Finance/pages/YearEndPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))

const data = (extra = {}) => ({
  data: {
    data: {
      years: [{ id: 2, name: '2026-2027', starts_on: '2026-09-01', is_current: false }, { id: 1, name: '2025-2026', starts_on: '2025-09-01', is_current: true }],
      from_year_id: 1,
      students: [
        { student_id: 5, student_number: 'S-1', name_ar: 'سارة', name_en: 'Sara', remaining: '600.00', overdue: '200.00' },
        { student_id: 6, student_number: 'S-2', name_ar: 'عمر', name_en: 'Omar', remaining: '250.00', overdue: '0.00' },
      ],
      total: '850.00',
      moves: [{ id: 9, student: { id: 7, name_ar: 'ليلى', name_en: 'Layla' }, from: '2024-2025', to: '2025-2026', amount: '100.00', date: '2025-07-01', reverted: false, can_undo: true }],
      currency: 'JOD',
      can_manage: true,
      ...extra,
    },
  },
})
const wrap = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter><YearEndPage base="/teacher" /></MemoryRouter></QueryClientProvider>)

beforeEach(async () => { vi.resetAllMocks(); await i18n.changeLanguage('en') })

describe('YearEndPage', () => {
  it('lists who owes, picks the next year by default, and carries the chosen students after a confirmation', async () => {
    api.get.mockResolvedValue(data())
    api.post.mockResolvedValue({ data: { data: { carried: 1, total: '600.00' } } })
    wrap()
    expect(await screen.findByText('Sara')).toBeInTheDocument()
    expect(screen.getByText('Omar')).toBeInTheDocument()
    expect(screen.getByRole('combobox', { name: 'New year' })).toHaveValue('2') // the year that follows

    fireEvent.click(screen.getByRole('checkbox', { name: 'Carry Omar' })) // leave Omar out
    fireEvent.click(screen.getByRole('button', { name: 'Carry the balance of 1 students' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Carry over' }))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/finance/year-end', { from_year_id: 1, to_year_id: 2, first_due: '2026-09-01', installments: 1, student_ids: [5] }))
    expect(await screen.findByRole('status')).toHaveTextContent('The balance of 1 students was carried over.')
  })

  it('undoes a past move after a confirmation', async () => {
    api.get.mockResolvedValue(data())
    api.post.mockResolvedValue({ data: { data: { id: 9 } } })
    wrap()
    fireEvent.click(await screen.findByRole('button', { name: 'Undo' }))
    fireEvent.click((await screen.findAllByRole('button', { name: 'Undo' })).pop())
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/finance/year-end/9/undo'))
  })

  it('is read only for the principal and shows when nobody owes', async () => {
    api.get.mockResolvedValue(data({ can_manage: false, students: [], total: '0.00', moves: [] }))
    wrap()
    expect(await screen.findByText('No student owes anything for this year.')).toBeInTheDocument()
    expect(screen.getByText('Only the accountant can carry balances over. You can read them.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Carry the balance/ })).not.toBeInTheDocument()
  })

  it('shows the server message when the move is refused', async () => {
    api.get.mockResolvedValue(data())
    api.post.mockRejectedValue({ response: { data: { errors: { to_year_id: ['The new year must start after the year being closed.'] } } } })
    wrap()
    fireEvent.click(await screen.findByRole('button', { name: 'Carry the balance of 2 students' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Carry over' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('must start after')
  })
})
