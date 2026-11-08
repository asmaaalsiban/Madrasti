import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import TimetablePage from '../src/features/Scheduling/pages/TimetablePage'
import TeacherAvailabilityPage from '../src/features/Scheduling/pages/TeacherAvailabilityPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))

const renderPage = (ui) =>
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter>{ui}</MemoryRouter></QueryClientProvider>)

const paged = (data) => ({ data: { data, meta: { current_page: 1, last_page: 1 } } })
const periods = [0, 1].flatMap((d) => [1, 2].map((n) => ({ id: d * 2 + n, day_of_week: d, number: n, starts_at: '07:45', ends_at: '08:30' })))
const math = { id: 5, name_en: 'Math', name_ar: 'رياضيات' }
const lesson = { id: 11, period_id: 1, subject: math, teacher: { id: 91, name: 'Sara' }, classroom: { id: 3, name: '5-A' }, room: null, is_locked: true }

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
})

describe('editing a lesson', () => {
  beforeEach(() => api.get.mockImplementation((url) => {
    if (url === '/classrooms') return Promise.resolve(paged([{ id: 3, name: '5-A' }]))
    if (url === '/staff') return Promise.resolve(paged([{ id: 91, name: 'Sara' }]))
    if (url === '/timetable') return Promise.resolve({ data: { data: { periods, entries: [lesson] } } })
    if (url === '/teaching-assignments') return Promise.resolve(paged([{ id: 1, subject_id: 5, subject: math, teacher: { id: 91, name: 'Sara' } }, { id: 2, subject_id: 6, subject: { id: 6, name_en: 'Art', name_ar: 'فنون' }, teacher: { id: 92, name: 'Omar' } }]))
    return Promise.resolve(paged([]))
  }))

  it('places the chosen subject in an empty cell and shows the teacher', async () => {
    api.put.mockResolvedValue({ data: { data: lesson } })
    renderPage(<TimetablePage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Monday Period 1' }))
    await screen.findByRole('option', { name: 'Art' })
    fireEvent.change(screen.getByLabelText('Subject'), { target: { value: '6' } })
    expect(screen.getByText('Teacher: Omar')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/timetable/entries', { classroom_id: 3, period_id: 3, subject_id: 6 }))
  })

  it('places a joint lesson when the option is ticked', async () => {
    api.put.mockResolvedValue({ data: { data: { ...lesson, is_joint: true } } })
    renderPage(<TimetablePage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Monday Period 1' }))
    await screen.findByRole('option', { name: 'Art' })
    fireEvent.change(screen.getByLabelText('Subject'), { target: { value: '6' } })
    fireEvent.click(screen.getByLabelText(/Joint lesson/))
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/timetable/entries', expect.objectContaining({ subject_id: 6, joint: true })))
  })

  it('shows the reason when the lesson is refused', async () => {
    api.put.mockRejectedValue({ response: { data: { errors: { period_id: ['The teacher is already teaching 5-B in this period.'] } } } })
    renderPage(<TimetablePage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Monday Period 1' }))
    await screen.findByRole('option', { name: 'Art' })
    fireEvent.change(screen.getByLabelText('Subject'), { target: { value: '6' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('already teaching 5-B')
  })

  it('unlocks and empties an existing lesson', async () => {
    api.put.mockResolvedValue({ data: { data: { ...lesson, is_locked: false } } })
    api.delete.mockResolvedValue({})
    renderPage(<TimetablePage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Sunday Period 1' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Unlock' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/timetable/entries/11/lock', { locked: false }))
    fireEvent.click(await screen.findByRole('button', { name: 'Sunday Period 1' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Empty this period' }))
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/timetable/entries/11'))
  })
})

describe('TeacherAvailabilityPage', () => {
  beforeEach(() => api.get.mockImplementation((url) => {
    if (url === '/staff') return Promise.resolve(paged([{ id: 91, name: 'Sara' }]))
    if (url === '/periods') return Promise.resolve({ data: { data: { periods } } })
    if (url === '/teachers/91/unavailability') return Promise.resolve({ data: { data: { period_ids: [2] } } })
    return Promise.resolve(paged([]))
  }))

  it('shows the saved periods, saves the changed ones and reports clashes', async () => {
    api.put.mockResolvedValue({ data: { data: { period_ids: [2, 3], clashes: [{ id: 1, classroom: '5-A' }] } } })
    renderPage(<TeacherAvailabilityPage />)
    expect(await screen.findAllByText(/Unavailable/)).toHaveLength(1)
    fireEvent.click(screen.getByRole('button', { name: 'Monday Period 1' }))
    expect(screen.getAllByText(/Unavailable/)).toHaveLength(2)
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/teachers/91/unavailability', { period_ids: [2, 3] }))
    expect(await screen.findByText('5-A')).toBeInTheDocument()
  })
})
