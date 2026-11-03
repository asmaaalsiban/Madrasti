import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import TransportPage from '../src/features/Transport/TransportPage'
import MyTransportPage from '../src/features/Transport/MyTransportPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: { role: 'parent', children: [{ id: 5, name_ar: 'سارة', name_en: 'Sara' }] } }) }))

const wrap = (ui, path = '/', route = '/') => render(
  <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter initialEntries={[path]}><Routes><Route path={route} element={ui} /></Routes></MemoryRouter></QueryClientProvider>,
)

const overview = {
  max_rounds: 3, riders: 1,
  rounds: [{ id: 1, name_ar: 'الجولة الأولى', name_en: 'First round', starts_at: '07:30', ends_at: '13:00', riders: 1 }, { id: 2, name_ar: 'الجولة الثانية', name_en: 'Second round', starts_at: null, ends_at: null, riders: 0 }],
  buses: [{ id: 9, number: '1', driver_name: 'Abu Ali', driver_phone: '0790000000', capacity: 30, area_from: 'Downtown', area_to: 'School', notes: null, is_active: true, round_ids: [1], load: { 1: { pickup: 1, dropoff: 0 } } }],
}

beforeEach(async () => { vi.resetAllMocks(); await i18n.changeLanguage('en') })

describe('transport', () => {
  it('the principal sees buses with their driver, area and load, and can add one for the rounds', async () => {
    api.get.mockResolvedValue({ data: overview })
    api.post.mockResolvedValue({ data: {} })
    wrap(<TransportPage />)
    expect(await screen.findByText('Bus 1')).toBeInTheDocument()
    expect(screen.getByText('Abu Ali')).toBeInTheDocument()
    expect(screen.getByText('From Downtown to School')).toBeInTheDocument()
    expect(screen.getByText('To school 1 · Home 0 (of 30 seats)')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Add a bus' }))
    fireEvent.change(screen.getByLabelText('Bus number'), { target: { value: '2' } })
    fireEvent.change(screen.getByLabelText('Driver name'), { target: { value: 'Abu Omar' } })
    fireEvent.change(screen.getByLabelText('Area from'), { target: { value: 'North' } })
    fireEvent.change(screen.getByLabelText('Area to'), { target: { value: 'School' } })
    fireEvent.click(screen.getByLabelText('Second round'))
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/transport/buses', expect.objectContaining({ number: '2', driver_name: 'Abu Omar', round_ids: [2], capacity: 30 })))
  })

  it('rounds tab lets you add a round until the maximum', async () => {
    api.get.mockResolvedValue({ data: { ...overview, max_rounds: 2 } })
    wrap(<TransportPage />)
    fireEvent.click(await screen.findByRole('tab', { name: 'Rounds' }))
    expect(screen.getByRole('button', { name: 'Add a round' })).toBeDisabled()
    expect(screen.getByText('First round')).toBeInTheDocument()
  })

  it('a parent sees the bus to school and a different bus home', async () => {
    api.get.mockResolvedValue({ data: { data: { round: { name_ar: 'الأولى', name_en: 'First round', starts_at: '07:30', ends_at: null }, pickup_bus: { number: '1', driver_name: 'Abu Ali', driver_phone: null, area_from: 'A', area_to: 'B' }, dropoff_bus: { number: '4', driver_name: 'Abu Saad', driver_phone: null, area_from: 'C', area_to: 'D' } } } })
    wrap(<MyTransportPage />, '/parent/children/5/transport', '/parent/children/:id/transport')
    expect(await screen.findByText('Bus 1')).toBeInTheDocument()
    expect(screen.getByText('Bus 4')).toBeInTheDocument()
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent("Sara's bus")
    expect(api.get).toHaveBeenCalledWith('/students/5/transport')
  })

  it('shows a message when the student has no bus', async () => {
    api.get.mockResolvedValue({ data: { data: null } })
    wrap(<MyTransportPage />, '/parent/children/5/transport', '/parent/children/:id/transport')
    expect(await screen.findByText('No school bus is set for this student.')).toBeInTheDocument()
  })
})
