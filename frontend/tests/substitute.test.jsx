import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import TransportPage from '../src/features/Transport/TransportPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))

const wrap = (ui) => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter>{ui}</MemoryRouter></QueryClientProvider>)
const round = { id: 1, name_ar: 'صباحية', name_en: 'Morning 1', period: 'morning', starts_at: '06:30', ends_at: '07:30', riders: 0 }
const bus = { id: 5, number: '7', driver_id: 21, driver: { id: 21, name: 'Ahmad', phone: null }, round_escorts: { 1: { id: 31, name: 'Miss Huda', phone: null } }, driver_name: 'Ahmad', capacity: 20, area_from: 'A', area_to: 'B', is_active: true, round_ids: [1], load: {} }
const overview = (extra = {}) => ({ data: { rounds: [round], buses: [bus], riders: 0, drivers: [], escorts: [{ id: 31, name: 'Miss Huda' }, { id: 32, name: 'Miss Rana' }], substitutes: [], ...extra } })

beforeEach(async () => { vi.resetAllMocks(); await i18n.changeLanguage('en') })

describe('stand-in escort', () => {
  it('sets a stand-in for a day on a round without touching the usual escort', async () => {
    api.get.mockResolvedValue(overview())
    api.put.mockResolvedValue({ data: { data: {} } })
    wrap(<TransportPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Stand-in Morning 1' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Stand-in escort'), { target: { value: '32' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalled())
    const [url, body] = api.put.mock.calls[0]
    expect(url).toBe('/transport/substitutes')
    expect(body).toMatchObject({ bus_id: 5, round_id: 1, escort_id: 32 })
    expect(body.date).toMatch(/^\d{4}-\d{2}-\d{2}$/)
  })

  it('lists the coming stand-ins and removes one', async () => {
    api.get.mockResolvedValue(overview({ substitutes: [{ id: 9, date: '2099-01-05', bus_id: 5, round_id: 1, escort: { id: 32, name: 'Miss Rana' } }] }))
    api.put.mockResolvedValue({ data: { data: {} } })
    wrap(<TransportPage />)
    expect(await screen.findByText('2099-01-05: stand-in Miss Rana')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Stand-in Morning 1' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Remove 2099-01-05' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/transport/substitutes', { date: '2099-01-05', bus_id: 5, round_id: 1, escort_id: null }))
  })
})
