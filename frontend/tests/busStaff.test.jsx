import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import TransportPage from '../src/features/Transport/TransportPage'
import MyBusPage from '../src/features/Transport/MyBusPage'
import RideCard from '../src/features/Transport/RideCard'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))

const wrap = (ui) => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter>{ui}</MemoryRouter></QueryClientProvider>)
const round = { id: 1, name_ar: 'صباحية', name_en: 'Morning 1', period: 'morning', starts_at: '06:30', ends_at: '07:30', riders: 0 }
const overview = (extra = {}) => ({
  data: { rounds: [round], buses: [], riders: 0, drivers: [{ id: 21, name: 'Ahmad Al-Sultani', phone: '0790001111' }], escorts: [{ id: 31, name: 'Miss Huda', phone: '0790002222' }], ...extra },
})

beforeEach(async () => { vi.resetAllMocks(); await i18n.changeLanguage('en') })

describe('bus: driver and escort from the staff', () => {
  it('chooses the driver and the escort from the staff and sends their ids (no typed name)', async () => {
    api.get.mockResolvedValue(overview())
    api.post.mockResolvedValue({ data: { data: { id: 5 } } })
    wrap(<TransportPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Add a bus' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Bus number'), { target: { value: '7' } })
    fireEvent.change(within(dialog).getByLabelText('Driver'), { target: { value: '21' } })
    expect(within(dialog).queryByLabelText('Driver name')).not.toBeInTheDocument() // no need to type a name
    fireEvent.click(within(dialog).getByLabelText('Morning 1')) // the bus serves this round, so its escort can be chosen
    fireEvent.change(within(dialog).getByLabelText('Escort Morning 1'), { target: { value: '31' } })
    fireEvent.change(within(dialog).getByLabelText('Area from'), { target: { value: 'Downtown' } })
    fireEvent.change(within(dialog).getByLabelText('Area to'), { target: { value: 'School' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(api.post).toHaveBeenCalled())
    const [url, body] = api.post.mock.calls[0]
    expect(url).toBe('/transport/buses')
    expect(body.driver_id).toBe(21)
    expect(body.round_escorts).toEqual({ 1: 31 })
    expect(body.escort_id).toBeUndefined()
    expect(body.driver_name).toBeUndefined()
  })

  it('still lets a driver who is not registered be typed by hand', async () => {
    api.get.mockResolvedValue(overview())
    api.post.mockResolvedValue({ data: { data: { id: 5 } } })
    wrap(<TransportPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Add a bus' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Bus number'), { target: { value: '8' } })
    fireEvent.change(within(dialog).getByLabelText('Driver'), { target: { value: 'other' } })
    fireEvent.change(within(dialog).getByLabelText('Driver name'), { target: { value: 'Abu Ali' } })
    fireEvent.change(within(dialog).getByLabelText('Area from'), { target: { value: 'A' } })
    fireEvent.change(within(dialog).getByLabelText('Area to'), { target: { value: 'B' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalled())
    expect(api.post.mock.calls[0][1]).toMatchObject({ driver_id: null, driver_name: 'Abu Ali', round_escorts: {} })
  })

  it('shows the driver and the escort on the bus card', async () => {
    api.get.mockResolvedValue(overview({ buses: [{ id: 5, number: '7', driver_id: 21, driver: { id: 21, name: 'Ahmad Al-Sultani', phone: '0790001111' }, round_escorts: { 1: { id: 31, name: 'Miss Huda', phone: '0790002222' } }, driver_name: 'Ahmad Al-Sultani', driver_phone: '0790001111', capacity: 20, area_from: 'A', area_to: 'B', is_active: true, round_ids: [1], load: {} }] }))
    wrap(<TransportPage />)
    expect(await screen.findByText('Ahmad Al-Sultani')).toBeInTheDocument()
    expect(screen.getByText('Miss Huda')).toBeInTheDocument()
  })
})

describe('MyBusPage', () => {
  it('shows the bus, the escort, and the students of each round with a parent to call', async () => {
    api.get.mockResolvedValue({ data: { data: [{
      id: 5, number: '7', area_from: 'Downtown', area_to: 'School', capacity: 20, notes: null, my_part: 'driver',
      driver: { id: 21, name: 'Ahmad Al-Sultani', phone: '0790001111' },
      rounds: [{ ...round, escort: { id: 31, name: 'Miss Huda', phone: '0790002222' }, students: [{ id: 1, name_ar: 'سارة', name_en: 'Sara', student_number: 'B-1', classroom: '5-A', parent_name: 'Mum Sara', parent_phone: '0795550000' }] }],
    }] } })
    wrap(<MyBusPage />)
    expect(await screen.findByText('Bus 7')).toBeInTheDocument()
    expect(screen.getByText('You are the driver')).toBeInTheDocument()
    expect(screen.getByText('Miss Huda')).toBeInTheDocument()
    expect(screen.getByText('Sara')).toBeInTheDocument()
    expect(screen.getByText('5-A')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /0795550000/ })).toHaveAttribute('href', 'tel:0795550000')
    expect(api.get).toHaveBeenCalledWith('/transport/mine')
  })

  it('says so when no bus is assigned', async () => {
    api.get.mockResolvedValue({ data: { data: [] } })
    wrap(<MyBusPage />)
    expect(await screen.findByText('No bus is assigned to you yet.')).toBeInTheDocument()
  })
})

describe('RideCard', () => {
  it('shows the escort of the childs bus', () => {
    const bus = { id: 5, number: '7', driver_name: 'Ahmad Al-Sultani', driver_phone: '0790001111', escort_name: 'Miss Huda', escort_phone: '0790002222', area_from: 'A', area_to: 'B' }
    wrap(<RideCard ride={{ pickup_round: round, pickup_bus: bus, dropoff_round: null, dropoff_bus: null }} />)
    expect(screen.getByText('Miss Huda')).toBeInTheDocument()
  })
})
