import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import SchoolDayPage from '../src/features/Scheduling/pages/SchoolDayPage'
import TimetablePage from '../src/features/Scheduling/pages/TimetablePage'

vi.mock('../src/api/client', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))

const renderPage = (ui) =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  )

const paged = (data) => ({ data: { data, meta: { current_page: 1, last_page: 1 } } })
const setup = { days: [0, 1, 2, 3, 4], periods_per_day: 7, starts_at: '07:45', period_minutes: 45, gap_minutes: 5, long_break_after: 3, long_break_minutes: 20 }
const periods = [0, 1].flatMap((d) => [1, 2].map((n) => ({ id: d * 2 + n, day_of_week: d, number: n, starts_at: n === 1 ? '07:45' : '08:35', ends_at: n === 1 ? '08:30' : '09:20' })))

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
})

describe('SchoolDayPage', () => {
  beforeEach(() => api.get.mockResolvedValue({ data: { data: { setup, periods: [] } } }))

  it('shows the current setup and a preview of the bells', async () => {
    renderPage(<SchoolDayPage />)
    expect(await screen.findByLabelText('Periods per day')).toHaveValue(7)
    expect(screen.getByLabelText('First period starts at')).toHaveValue('07:45')
    for (const day of ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday']) expect(screen.getByRole('checkbox', { name: day })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'Friday' })).not.toBeChecked()
    expect(screen.getByText('Period 4')).toBeInTheDocument()
    expect(screen.getByText('10:30 - 11:15')).toBeInTheDocument() // after the 20 minute break following period 3
    expect(screen.getByText('13:00 - 13:45')).toBeInTheDocument()
  })

  it('saves the changed setup', async () => {
    api.put.mockResolvedValue({ data: { data: { setup, periods: [] } } })
    renderPage(<SchoolDayPage />)
    await screen.findByLabelText('Periods per day')
    fireEvent.click(screen.getByRole('checkbox', { name: 'Friday' }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'Thursday' }))
    fireEvent.change(screen.getByLabelText('Periods per day'), { target: { value: '8' } })
    fireEvent.change(screen.getByLabelText('First period starts at'), { target: { value: '08:00' } })
    fireEvent.change(screen.getByLabelText('Break after period'), { target: { value: '' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/periods/setup', {
      days: [0, 1, 2, 3, 5], periods_per_day: 8, starts_at: '08:00', period_minutes: 45, gap_minutes: 5, long_break_after: null, long_break_minutes: null,
    }))
    expect(await screen.findByText('School day saved.')).toBeInTheDocument()
  })

  it('shows the server error when periods are in use', async () => {
    api.put.mockRejectedValue({ response: { status: 422, data: { errors: { periods_per_day: ['Some of the periods this would remove are used by the timetable.'] } } } })
    renderPage(<SchoolDayPage />)
    await screen.findByLabelText('Periods per day')
    fireEvent.change(screen.getByLabelText('Periods per day'), { target: { value: '6' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(await screen.findByText('Some of the periods this would remove are used by the timetable.')).toBeInTheDocument()
  })

  it('has Arabic labels', async () => {
    await i18n.changeLanguage('ar')
    renderPage(<SchoolDayPage />)
    expect(await screen.findByRole('checkbox', { name: 'الأحد' })).toBeChecked()
    expect(screen.getByRole('heading', { name: 'اليوم الدراسي' })).toBeInTheDocument()
  })
})

describe('TimetablePage', () => {
  const entry = (id, periodId, subject, teacher, classroom, locked = false) => ({
    id, period_id: periodId, subject: { id: id, name_en: subject, name_ar: `${subject}-ar` }, teacher: { id: 90 + id, name: teacher }, classroom: { id: 3, name: classroom }, room: null, is_locked: locked,
  })
  const grid = {
    periods,
    entries: [entry(1, 1, 'Math', 'Sara', '5-A'), entry(2, 2, 'Arabic', 'Omar', '5-A', true), entry(3, 3, 'Science', 'Rana', '5-A')],
  }
  const serve = () => api.get.mockImplementation((url) => {
    if (url === '/classrooms') return Promise.resolve(paged([{ id: 3, name: '5-A' }, { id: 4, name: '5-B' }]))
    if (url === '/staff') return Promise.resolve(paged([{ id: 91, name: 'Sara' }, { id: 92, name: 'Omar' }]))
    if (url === '/timetable') return Promise.resolve({ data: { data: grid } })
    return Promise.resolve(paged([]))
  })

  it('shows the week of the first classroom: periods as rows and days as columns', async () => {
    serve()
    renderPage(<TimetablePage />)
    expect(await screen.findByText('Math')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('/timetable', { params: { classroom_id: 3 } })
    expect(screen.getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['', 'Sunday', 'Monday'])
    const first = screen.getByRole('row', { name: /Period 1/ })
    expect(within(first).getByText('07:45 - 08:30')).toBeInTheDocument()
    expect(within(first).getByText('Math')).toBeInTheDocument()
    expect(within(first).getByText('Sara')).toBeInTheDocument()
    expect(within(screen.getByRole('row', { name: /Period 2/ })).getByText('Arabic')).toBeInTheDocument()
    expect(screen.getByLabelText('Locked')).toBeInTheDocument()
  })

  it('switches to the week of a teacher', async () => {
    serve()
    renderPage(<TimetablePage />)
    await screen.findByText('Math')
    fireEvent.click(screen.getByRole('tab', { name: 'By teacher' }))
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/timetable', { params: { teacher_id: 91 } }))
    expect(screen.getByLabelText('Teacher')).toHaveValue('91')
  })

  it('previews the generation, shows what could not be placed and applies it', async () => {
    serve()
    const preview = { dry_run: true, seed: 5, attempts: 3, total: 315, placed: 313, locked: 0, unplaced: [{ classroom: '5-A', subject: 'Art', subject_ar: 'فنية', count: 2 }], without_teacher: [{ classroom: '5-B', subject: 'Music', subject_ar: 'موسيقى', periods: 1 }] }
    api.post.mockResolvedValueOnce({ data: { data: preview } }).mockResolvedValueOnce({ data: { data: { ...preview, dry_run: false } } })
    renderPage(<TimetablePage />)
    await screen.findByText('Math')
    fireEvent.click(screen.getByRole('button', { name: 'Generate the timetable' }))
    const dialog = screen.getByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Preview' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/timetable/generate', { dry_run: true }))
    expect(await within(dialog).findByText('313 of 315 lessons can be placed.')).toBeInTheDocument()
    expect(within(dialog).getByText('5-A · Art: 2')).toBeInTheDocument()
    expect(within(dialog).getByText('5-B · Music: no teacher assigned')).toBeInTheDocument()

    fireEvent.click(within(dialog).getByRole('button', { name: 'Apply the timetable' }))
    await waitFor(() => expect(api.post).toHaveBeenLastCalledWith('/timetable/generate', { seed: 5 }))
    expect(await within(dialog).findByText('The timetable was saved: 313 lessons.')).toBeInTheDocument()
  })

  it('shows the server message when the school day is not set up', async () => {
    serve()
    api.post.mockRejectedValue({ response: { status: 422, data: { message: 'Set up the school day first (days, periods and times).' } } })
    renderPage(<TimetablePage />)
    await screen.findByText('Math')
    fireEvent.click(screen.getByRole('button', { name: 'Generate the timetable' }))
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Preview' }))
    expect(await within(screen.getByRole('dialog')).findByRole('alert')).toHaveTextContent('Set up the school day first')
  })

  it('uses Arabic subject names and day names', async () => {
    await i18n.changeLanguage('ar')
    serve()
    renderPage(<TimetablePage />)
    expect(await screen.findByText('Math-ar')).toBeInTheDocument()
    expect(screen.getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['', 'الأحد', 'الإثنين'])
  })
})
