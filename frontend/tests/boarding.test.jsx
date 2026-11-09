import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import MyBusPage from '../src/features/Transport/MyBusPage'
import BoardingReportPage from '../src/features/Transport/BoardingReportPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), put: vi.fn() } }))
const wrap = (ui) => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter>{ui}</MemoryRouter></QueryClientProvider>)

const kid = (id, name) => ({ id, name_ar: name, name_en: name, student_number: `B-${id}`, classroom: '5-A', parent_name: null, parent_phone: null })
const bus = { id: 5, number: '7', area_from: 'A', area_to: 'B', capacity: 20, notes: null, my_part: 'escort', driver: null, escort: { id: 31, name: 'Miss Huda', phone: null },
  rounds: [{ id: 1, name_ar: 'صباح', name_en: 'Morning round', period: 'morning', starts_at: '06:30', ends_at: '07:30', students: [kid(1, 'Sami'), kid(2, 'Lina')] }] }

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
  api.get.mockImplementation(async (url) => (url === '/transport/mine' ? { data: { data: [bus] } } : { data: { data: { date: '2026-11-10', today: '2026-11-10', marks: [{ bus_id: 5, round_id: 1, student_id: 2, status: 'not_boarded' }] } } }))
  api.put.mockResolvedValue({ data: { data: {} } })
})

describe('boarding ticks on the escort page', () => {
  it('shows the saved ticks and the counts, and ticks a student as boarded (and clears it with a second press)', async () => {
    wrap(<MyBusPage />)
    expect(await screen.findByText('0 boarded · 1 did not board · 1 not ticked yet')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Lina: Did not board' })).toHaveAttribute('aria-pressed', 'true')

    fireEvent.click(screen.getByRole('button', { name: 'Sami: Boarded' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/transport/boarding', { date: '2026-11-10', bus_id: 5, round_id: 1, student_id: 1, status: 'boarded' }))
    expect(await screen.findByText('1 boarded · 1 did not board · 0 not ticked yet')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Sami: Boarded' }))
    await waitFor(() => expect(api.put).toHaveBeenLastCalledWith('/transport/boarding', expect.objectContaining({ student_id: 1, status: null })))
  })

  it('has an "Everyone boarded" button and says parents are not told', async () => {
    wrap(<MyBusPage />)
    fireEvent.click(await screen.findByRole('button', { name: /Everyone boarded/ }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/transport/boarding/all', { date: '2026-11-10', bus_id: 5, round_id: 1 }))
    expect(screen.getByText(/parents are not told/)).toBeInTheDocument()
  })
})

describe('BoardingReportPage (admin)', () => {
  it('shows each bus and round with who did not board', async () => {
    api.get.mockResolvedValue({ data: { data: { date: '2026-11-10', rows: [{ bus: { id: 5, number: '7', escort: 'Miss Huda', driver: null }, round: { id: 1, name_ar: 'صباح', name_en: 'Morning round', period: 'morning' }, total: 3, boarded: 1, not_boarded: 1, not_ticked: 1, missed: [{ id: 2, name_ar: 'لينا', name_en: 'Lina' }] }] } } })
    wrap(<BoardingReportPage />)
    expect(await screen.findByText('Bus 7')).toBeInTheDocument()
    expect(screen.getByText('1 / 3')).toBeInTheDocument()
    expect(screen.getByText('Lina')).toBeInTheDocument()
  })
})
